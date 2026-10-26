<?php

namespace App\ClubUnio\Api;

use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ApiProviderWork
{
    public function table()
    {
        return DB::connection('clubunio')->table('clubora.api_provider_work');
    }

    public function stage(ApiContext $c, string $operation, int $id, array $input): array
    {
        $registry = app(ProviderApiRegistry::class);
        $input = $registry->validate($c, $operation, $id, $input);
        $key = (string) Str::uuid();
        $work = $this->table()->insertGetId([
            'tenant_id' => $c->tenant->id, 'integration_id' => $c->integration->id,
            'token_id' => $c->token->id, 'actor_id' => $c->actor->id,
            'request_key' => $key, 'operation' => $operation, 'object_id' => $id,
            'authorization_hash' => $c->authorizationFingerprint(),
            'encrypted_input' => Crypt::encryptString(json_encode($input, JSON_THROW_ON_ERROR)),
            'state' => $registry->definitions()[$operation]['approval'] === 'admin' ? 'awaiting_approval' : 'pending',
        ]);
        $this->event($work, $c->actor, 'staged');

        return $this->show($c, $work);
    }

    public function show(ApiContext $c, int $id): array
    {
        $row = $this->owned($c, $id);
        $this->authorizeScopes($c, $row->operation);

        return $this->dto($row);
    }

    private function owned(ApiContext $c, int $id): object
    {
        return $this->table()->where('tenant_id', $c->tenant->id)
            ->where('integration_id', $c->integration->id)->where('actor_id', $c->actor->id)->find($id) ?? abort(404);
    }

    private function authorizeScopes(ApiContext $c, string $operation): void
    {
        $definition = app(ProviderApiRegistry::class)->definitions()[$operation] ?? abort(404);
        foreach ($definition['scopes'] as $scope => [$module, $function]) {
            $c->authorize($scope, $module, $function);
        }
    }

    public function process(ApiContext $c, int $id): array
    {
        $row = $this->owned($c, $id);
        $this->authorizeScopes($c, $row->operation);
        abort_unless(app(ProviderApiRegistry::class)->definitions()[$row->operation]['approval'] === 'direct', 409, 'Admin approval required.');

        return $this->execute($c, $id);
    }

    public function approve(Account $admin, Tenant $tenant, int $id, int $revision): array
    {
        $this->admin($admin, $tenant);
        $row = $this->table()->where('tenant_id', $tenant->id)->find($id) ?? abort(404);
        abort_unless(app(ProviderApiRegistry::class)->definitions()[$row->operation]['approval'] === 'admin', 409);

        return $this->execute($this->originalContext($row, $tenant), $id, $admin, $revision);
    }

    private function originalContext(object $row, Tenant $tenant): ApiContext
    {
        $integration = ApiIntegration::where('tenant_id', $tenant->id)->findOrFail($row->integration_id);
        $token = ApiAccessToken::where('tokenable_id', $integration->id)->findOrFail($row->token_id);

        return new ApiContext(Account::findOrFail($row->actor_id), $tenant, $integration, $token);
    }

    private function execute(ApiContext $c, int $id, ?Account $admin = null, ?int $revision = null): array
    {
        $db = DB::connection('clubunio');
        abort_unless($db->transactionLevel() === 0, 409, 'Provider work requires a committed request.');
        $row = $db->transaction(function () use ($c, $id, $admin, $revision) {
            Tenant::whereKey($c->tenant->id)->lockForUpdate()->firstOrFail();
            if ($admin) {
                $this->admin($admin, $c->tenant);
            }
            $row = $this->table()->where('tenant_id', $c->tenant->id)->where('integration_id', $c->integration->id)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $this->authorizeScopes($c, $row->operation);
            abort_unless(hash_equals($row->authorization_hash, $c->authorizationFingerprint()), 409, 'Authorization changed; submit a new request.');
            if ($admin) {
                abort_unless($row->state === 'awaiting_approval' && $revision === $row->revision, 409);
            }
            if ($row->state === 'succeeded') {
                return $row;
            }
            abort_unless($row->token_id === $c->token->id && $row->actor_id === $c->actor->id, 403);
            abort_unless($revision === null || $revision === $row->revision, 409);
            abort_unless($row->state === ($admin ? 'awaiting_approval' : 'pending'), 409, 'Work already started; inspect its recorded outcome.');
            app(ProviderApiRegistry::class)->validate($c, $row->operation, $row->object_id, $this->input($row));
            $this->table()->where('id', $id)->update(['state' => 'running', 'approved_by' => $admin?->id,
                'revision' => $row->revision + 1, 'started_at' => now(), 'updated_at' => now()]);
            $this->event($id, $admin ?? $c->actor, $admin ? 'approved_started' : 'started');

            return $row;
        });
        if ($row->state === 'succeeded') {
            return $this->show($c, $id);
        }
        // The durable intent is committed before network I/O. Never reclaim or retry an uncertain paid/provider write.
        try {
            $this->authorizeScopes($c, $row->operation);
            if ($admin) {
                $this->admin($admin, $c->tenant);
            }
            $input = $this->input($row) + ['_request_key' => $row->request_key];
            $result = app(ProviderApiRegistry::class)->execute($c, $row->operation, $row->object_id, $input, $admin);
            $db->transaction(function () use ($id, $result, $c, $admin) {
                $confirmed = ($result['confirmed'] ?? true) === true;
                $this->table()->where('id', $id)->where('state', 'running')->update([
                    'state' => $confirmed ? 'succeeded' : 'attention', 'error_code' => $confirmed ? null : 'provider_unconfirmed',
                    'encrypted_result' => Crypt::encryptString(json_encode($result, JSON_THROW_ON_ERROR)),
                    'finished_at' => now(), 'updated_at' => now(), 'revision' => DB::connection('clubunio')->raw('revision + 1'),
                ]);
                $this->event($id, $admin ?? $c->actor, $confirmed ? 'succeeded' : 'attention', $confirmed ? null : 'provider_unconfirmed');
            });
        } catch (\Throwable $error) {
            $status = match (true) {
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                $error instanceof ValidationException => 422,
                $error instanceof ModelNotFoundException => 404,
                $error instanceof AuthorizationException => 403,
                default => 0,
            };
            $code = in_array($status, [401, 403, 404, 409, 422, 429, 502, 503, 504], true) ? 'status_'.$status : 'operation_unconfirmed';
            $this->table()->where('id', $id)->where('state', 'running')->update(['state' => 'attention', 'error_code' => $code,
                'finished_at' => now(), 'updated_at' => now(), 'revision' => DB::connection('clubunio')->raw('revision + 1')]);
            $this->event($id, $admin ?? $c->actor, 'attention', $code);
            Log::warning('clubunio.api.provider_attention', ['work_id' => $id, 'tenant_id' => $c->tenant->id, 'operation' => $row->operation, 'code' => $code]);
            throw $error;
        }

        return $this->show($c, $id);
    }

    public function adminRows(Account $admin, Tenant $tenant)
    {
        $this->admin($admin, $tenant);

        return $this->table()->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(50)
            ->get(['id', 'integration_id', 'operation', 'object_id', 'state', 'revision', 'error_code', 'created_at']);
    }

    public function inspect(Account $admin, Tenant $tenant, int $id): array
    {
        $this->admin($admin, $tenant);
        $row = $this->table()->where('tenant_id', $tenant->id)->find($id) ?? abort(404);

        return $this->dto($row) + ['input' => $this->input($row),
            'events' => DB::connection('clubunio')->table('clubora.api_provider_events')->where('work_id', $id)
                ->orderByDesc('id')->limit(20)->get(['event', 'actor_id', 'code', 'created_at'])];
    }

    public function reject(Account $admin, Tenant $tenant, int $id, int $revision): void
    {
        DB::connection('clubunio')->transaction(function () use ($admin, $tenant, $id, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->admin($admin, $tenant);
            $row = $this->table()->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->state === 'awaiting_approval' && $row->revision === $revision, 409);
            $this->table()->where('id', $id)->update(['state' => 'rejected', 'revision' => $revision + 1, 'updated_at' => now(), 'finished_at' => now()]);
            $this->event($id, $admin, 'rejected');
        });
    }

    private function admin(Account $admin, Tenant $tenant): void
    {
        abort_unless(app(ApiTokenService::class)->isAdmin($admin, $tenant), 403);
    }

    private function input(object $row): array
    {
        return json_decode(Crypt::decryptString($row->encrypted_input), true, flags: JSON_THROW_ON_ERROR);
    }

    private function dto(object $row): array
    {
        return ['id' => (int) $row->id, 'operation' => $row->operation, 'object_id' => (int) $row->object_id,
            'state' => $row->state, 'revision' => (int) $row->revision, 'error_code' => $row->error_code,
            'approved_by' => $row->approved_by ? (int) $row->approved_by : null,
            'created_at' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
            'started_at' => $row->started_at ? CarbonImmutable::parse($row->started_at)->toIso8601String() : null,
            'finished_at' => $row->finished_at ? CarbonImmutable::parse($row->finished_at)->toIso8601String() : null,
            'result' => $row->encrypted_result ? json_decode(Crypt::decryptString($row->encrypted_result), true, flags: JSON_THROW_ON_ERROR) : null];
    }

    private function event(int $id, Account $actor, string $event, ?string $code = null): void
    {
        DB::connection('clubunio')->table('clubora.api_provider_events')->insert(['work_id' => $id, 'actor_id' => $actor->id, 'event' => $event, 'code' => $code]);
    }
}
