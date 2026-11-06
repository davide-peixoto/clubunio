<?php

namespace App\ClubUnio\Assistant;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BackofficeAssistantService
{
    use Localizable;

    public function __construct(private AssistantAccess $guard, private AssistantProvider $provider,
        private AssistantReadTools $reads, private AssistantActionTools $writes) {}

    public function access(Account $actor, Tenant $tenant): bool
    {
        return $this->guard->allows($actor, $tenant);
    }

    private function authorize(Account $actor, Tenant $tenant): void
    {
        $this->guard->authorize($actor, $tenant);
        abort_unless(Schema::connection('clubunio')->hasTable('clubora.assistant_conversations'), 503);
    }

    private function table(string $name, Account $actor, Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.assistant_'.$name)
            ->where('tenant_id', $tenant->id)->where('account_id', $actor->id);
    }

    private function conversation(Account $actor, Tenant $tenant, int $id, bool $lock = false): object
    {
        // Keep the offset: Laravel's default DateTime binding drops it for PostgreSQL timestamptz.
        $query = $this->table('conversations', $actor, $tenant)->where('id', $id)
            ->where('updated_at', '>=', now()->subDays((int) config('clubunio-assistant.retention_days', 30))->toIso8601String());
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function encode(array $value): string
    {
        return Crypt::encryptString(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function decode(string $value): array
    {
        return json_decode(Crypt::decryptString($value), true, 64, JSON_THROW_ON_ERROR);
    }

    private function budget(Tenant $tenant): int
    {
        return (int) DB::connection('clubunio')->table('clubora.assistant_runs')->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', CarbonImmutable::now('Europe/Zurich')->startOfMonth()->toIso8601String())
            ->selectRaw('COALESCE(SUM(COALESCE(actual_microusd,reserved_microusd)),0) AS spent')->value('spent');
    }

    public function state(Account $actor, Tenant $tenant, ?int $conversationId = null): array
    {
        $this->authorize($actor, $tenant);
        $conversation = $conversationId ? $this->conversation($actor, $tenant, $conversationId) : null;
        $actions = $conversation ? $this->table('actions', $actor, $tenant)->where('conversation_id', $conversationId)->orderByDesc('id')->limit(30)->get()->map(function ($row) {
            $preview = $this->decode($row->preview_encrypted);

            return ['id' => (int) $row->id, 'title' => $preview['title'], 'changes' => $preview['changes'] ?? [],
                'effects' => $preview['effects'] ?? [], 'status' => $row->state === 'pending' && CarbonImmutable::parse($row->expires_at)->isPast() ? 'expired' : $row->state];
        })->all() : [];

        return ['conversation_id' => $conversation ? (int) $conversation->id : null,
            'messages' => $conversation ? $this->decode($conversation->transcript_encrypted) : [], 'actions' => $actions,
            'provider_enabled' => $this->provider->enabled(),
            'budget' => ['limit_usd' => config('clubunio-assistant.monthly_limit_microusd') / 1_000_000, 'used_usd' => $this->budget($tenant) / 1_000_000],
            'conversations' => $this->table('conversations', $actor, $tenant)->where('updated_at', '>=', now()->subDays((int) config('clubunio-assistant.retention_days', 30))->toIso8601String())
                ->orderByDesc('updated_at')->limit(20)->get(['id', 'created_at'])->map(fn ($r) => [
                    'id' => (int) $r->id, 'title' => __('assistant.conversation').' '.CarbonImmutable::parse($r->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i'),
                ])->all()];
    }

    private function createConversation(Account $actor, Tenant $tenant): int
    {
        return $this->table('conversations', $actor, $tenant)->insertGetId([
            'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'transcript_encrypted' => $this->encode([]),
        ]);
    }

    private function append(Account $actor, Tenant $tenant, int $id, array $messages, ?string $run = null): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $messages, $run) {
            $this->authorize($actor, $tenant);
            $row = $this->conversation($actor, $tenant, $id, true);
            abort_unless($row->busy_run === $run, 409);
            $history = array_slice(array_merge($this->decode($row->transcript_encrypted), $messages), -40);
            $this->table('conversations', $actor, $tenant)->where('id', $id)->update([
                'transcript_encrypted' => $this->encode($history), 'updated_at' => now()->toIso8601String(),
            ]);
        });
    }

    public function briefing(Account $actor, Tenant $tenant, ?int $conversationId = null): array
    {
        $this->authorize($actor, $tenant);
        $result = $this->reads->execute($actor, $tenant, 'read_daily_briefing', ['date' => null]);
        if ($conversationId) {
            $this->conversation($actor, $tenant, $conversationId);
        }
        $conversationId ??= $this->createConversation($actor, $tenant);
        $this->append($actor, $tenant, $conversationId, [['role' => 'assistant', 'content' => __('assistant.local_briefing'), 'results' => [$result]]]);

        return $this->state($actor, $tenant, $conversationId);
    }

    public function ask(Account $actor, Tenant $tenant, ?int $conversationId, string $text, ?string $requestKey = null): array
    {
        $this->authorize($actor, $tenant);
        if (! $this->provider->enabled()) {
            throw ValidationException::withMessages(['assistant' => __('assistant.provider_disabled')]);
        }
        $requestKey ??= (string) Str::uuid();
        Validator::make(['text' => $text, 'key' => $requestKey], ['text' => 'required|string|max:4000', 'key' => 'required|uuid'])->validate();
        $fingerprint = hash('sha256', json_encode([$conversationId, $text], JSON_THROW_ON_ERROR));
        $db = DB::connection('clubunio');
        $run = $db->transaction(function () use ($actor, $tenant, $conversationId, $requestKey, $fingerprint, $text) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            if ($conversationId) {
                $this->conversation($actor, $tenant, $conversationId);
            }
            $old = $this->table('runs', $actor, $tenant)->where('request_key', $requestKey)->first();
            if ($old) {
                abort_unless(hash_equals($old->fingerprint, $fingerprint) && $old->state === 'succeeded' && $old->conversation_id, 409);

                return $old;
            }
            $reserve = (int) config('clubunio-assistant.request_reservation_microusd');
            abort_unless($reserve >= 400_000, 503);
            if ($this->budget($tenant) + $reserve > (int) config('clubunio-assistant.monthly_limit_microusd')) {
                throw ValidationException::withMessages(['assistant' => __('assistant.budget_exhausted')]);
            }
            $conversationId ??= $this->createConversation($actor, $tenant);
            $row = $this->conversation($actor, $tenant, $conversationId, true);
            abort_if($row->busy_run !== null, 409);
            $id = (string) Str::uuid();
            $this->table('runs', $actor, $tenant)->insert(['id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $actor->id,
                'conversation_id' => $conversationId, 'request_key' => $requestKey, 'fingerprint' => $fingerprint,
                'state' => 'running', 'reserved_microusd' => $reserve]);
            $history = array_slice(array_merge($this->decode($row->transcript_encrypted), [['role' => 'user', 'content' => $text]]), -40);
            $this->table('conversations', $actor, $tenant)->where('id', $conversationId)->update([
                'busy_run' => $id, 'updated_at' => now()->toIso8601String(), 'transcript_encrypted' => $this->encode($history),
            ]);

            return $this->table('runs', $actor, $tenant)->where('id', $id)->first();
        });
        if ($run->state === 'succeeded') {
            return $this->state($actor, $tenant, (int) $run->conversation_id);
        }
        $conversationId = (int) $run->conversation_id;
        $cost = 0;
        $inputTokens = 0;
        $outputTokens = 0;
        try {
            $row = $this->conversation($actor, $tenant, $conversationId);
            $input = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], array_slice($this->decode($row->transcript_encrypted), -12));
            $tools = array_merge($this->reads->definitions(), $this->writes->definitions());
            $allowed = array_column($tools, 'name');
            $results = [];
            $answer = '';
            $prepared = [];
            for ($round = 0; $round < min(4, (int) config('clubunio-assistant.max_rounds')); $round++) {
                $this->authorize($actor, $tenant);
                $response = $this->provider->respond($input, $tools, app()->getLocale());
                $cost += $response['cost_microusd'];
                $inputTokens += $response['input_tokens'];
                $outputTokens += $response['output_tokens'];
                $this->authorize($actor, $tenant);
                $answer = $response['text'];
                if (! $response['calls']) {
                    break;
                }
                foreach ($response['calls'] as $call) {
                    $this->authorize($actor, $tenant);
                    abort_unless(in_array($call['name'], $allowed, true), 422);
                    $input[] = ['type' => 'function_call', 'call_id' => $call['call_id'], 'name' => $call['name'], 'arguments' => json_encode($call['arguments'], JSON_THROW_ON_ERROR)];
                    try {
                        if (str_starts_with($call['name'], 'read_')) {
                            $result = $this->reads->execute($actor, $tenant, $call['name'], $call['arguments']);
                            $results[] = $result;
                        } else {
                            $proposal = $this->writes->prepare($actor, $tenant, $call['name'], $call['arguments']);
                            $hash = hash('sha256', json_encode([$proposal['operation'], $proposal['arguments']], JSON_THROW_ON_ERROR));
                            if (! isset($prepared[$hash])) {
                                $prepared[$hash] = $proposal;
                            }
                            $result = ['status' => 'awaiting_human_confirmation', 'preview' => $proposal['preview']];
                        }
                    } catch (ValidationException $e) {
                        $result = ['error' => 'validation_failed', 'fields' => array_keys($e->errors())];
                    } catch (HttpException $e) {
                        $result = ['error' => 'operation_unavailable', 'status' => $e->getStatusCode()];
                    }
                    $input[] = ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
                }
                $answer = __('assistant.step_limit');
            }
            $db->transaction(function () use ($actor, $tenant, $conversationId, $run, $prepared, $answer, $results, $cost, $inputTokens, $outputTokens) {
                $this->authorize($actor, $tenant);
                $row = $this->conversation($actor, $tenant, $conversationId, true);
                abort_unless($row->busy_run === $run->id, 409);
                foreach ($prepared as $proposal) {
                    $this->table('actions', $actor, $tenant)->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                        'conversation_id' => $conversationId, 'operation' => $proposal['operation'],
                        'arguments_encrypted' => $this->encode($proposal['arguments']), 'preview_encrypted' => $this->encode($proposal['preview']),
                        'state' => 'pending', 'locale' => app()->getLocale(), 'expires_at' => now()->addMinutes((int) config('clubunio-assistant.confirmation_minutes', 10))->toIso8601String()]);
                }
                $this->append($actor, $tenant, $conversationId, [['role' => 'assistant', 'content' => $answer ?: __('assistant.results_ready'), 'results' => $results]], $run->id);
                $this->table('runs', $actor, $tenant)->where('id', $run->id)->update(['state' => 'succeeded', 'actual_microusd' => $cost, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]);
                $this->table('conversations', $actor, $tenant)->where('id', $conversationId)->update(['busy_run' => null]);
            });
        } catch (\Throwable $e) {
            $this->table('runs', $actor, $tenant)->where('id', $run->id)->update(['state' => 'attention', 'error_code' => $this->errorCode($e), 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]);
            $this->table('conversations', $actor, $tenant)->where('id', $conversationId)->where('busy_run', $run->id)->update(['busy_run' => null]);
            // Unknown charged requests retain their full reservation; never echo provider/SQL payloads.
            throw ValidationException::withMessages(['assistant' => __('assistant.request_failed').' ['.$run->id.']']);
        }

        return $this->state($actor, $tenant, $conversationId);
    }

    public function confirm(Account $actor, Tenant $tenant, int $actionId): array
    {
        $this->authorize($actor, $tenant);
        $action = DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $actionId) {
            $row = $this->table('actions', $actor, $tenant)->where('id', $actionId)->lockForUpdate()->first();
            abort_unless($row, 404);
            $conversation = $this->conversation($actor, $tenant, (int) $row->conversation_id, true);
            abort_if($conversation->busy_run, 409);
            if ($row->state === 'succeeded') {
                return $row;
            }
            abort_unless($row->state === 'pending' && CarbonImmutable::parse($row->expires_at)->isFuture(), 409);
            $this->authorize($actor, $tenant);
            $arguments = $this->decode($row->arguments_encrypted);
            $fresh = $this->withLocale($row->locale, fn () => $this->writes->prepare($actor, $tenant, $row->operation, $arguments));
            abort_unless($fresh['arguments'] === $arguments && $fresh['preview'] === $this->decode($row->preview_encrypted), 409);
            // Persist the claim before any external side effect. An interrupted run is never replayed.
            $this->table('actions', $actor, $tenant)->where('id', $row->id)->update(['state' => 'running']);

            return $row;
        });
        if ($action->state === 'succeeded') {
            return $this->state($actor, $tenant, (int) $action->conversation_id);
        }
        try {
            $this->authorize($actor, $tenant);
            $result = $this->writes->execute($actor, $tenant, $action->operation, $this->decode($action->arguments_encrypted));
            $this->table('actions', $actor, $tenant)->where('id', $action->id)->update([
                'state' => 'succeeded', 'result_encrypted' => $this->encode($result), 'completed_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            $this->table('actions', $actor, $tenant)->where('id', $action->id)->update(['state' => 'attention', 'error_code' => $this->errorCode($e), 'completed_at' => now()->toIso8601String()]);
            throw ValidationException::withMessages(['assistant' => __('assistant.action_failed').' [A'.$action->id.']']);
        }

        return $this->state($actor, $tenant, (int) $action->conversation_id);
    }

    public function cancel(Account $actor, Tenant $tenant, int $actionId): array
    {
        $this->authorize($actor, $tenant);
        $row = $this->table('actions', $actor, $tenant)->where('id', $actionId)->first();
        abort_unless($row, 404);
        $this->conversation($actor, $tenant, (int) $row->conversation_id);
        abort_unless($row->state === 'cancelled' || $this->table('actions', $actor, $tenant)->where('id', $actionId)->where('state', 'pending')->update(['state' => 'cancelled', 'completed_at' => now()->toIso8601String()]) === 1, 409);

        return $this->state($actor, $tenant, (int) $row->conversation_id);
    }

    private function errorCode(\Throwable $error): string
    {
        return match (true) {
            $error instanceof ConnectionException => 'provider_connection_uncertain',
            $error instanceof ValidationException => 'validation_failed',
            $error instanceof HttpException => 'http_'.$error->getStatusCode(),
            $error instanceof QueryException => 'database_failure',
            default => 'unexpected_failure',
        };
    }
}
