<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Setting, Tenant};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB, Validator};
use Illuminate\Support\Str;

/** Durable, at-most-once dispatch. Polling and uncertain requests never repeat paid calls. */
final class MobileTrainingWork
{
    private function table() { return DB::connection('clubunio')->table('clubora.mobile_ai_work'); }
    public function available(Tenant $tenant): bool
    {
        try { $this->configuration($tenant); return true; } catch (\Symfony\Component\HttpKernel\Exception\HttpException) { return false; }
    }
    private function configuration(Tenant $tenant): array
    {
        abort_unless(config('clubunio-mobile.training_ai.enabled', false) && config('clubunio-mobile.training_ai.cost_reviewed', false)
            && in_array($tenant->slug, config('clubunio-mobile.training_ai.tenant_slugs', []), true), 503, 'Trainings-KI noch nicht freigeschaltet.');
        $settings = [];
        $rows = Setting::where('tenant_id', $tenant->id)->whereIn('key', ['OPENAI_API_KEY','OPENAI_TRAINING_MODEL','OPENAI_TRAINING_VECTOR_STORE_ID'])->get();
        abort_unless($rows->count() === 3 && $rows->every(fn ($row) => $row->enabled), 503, 'Trainings-KI unvollständig konfiguriert.');
        foreach ($rows as $row) {
            try { $settings[$row->key] = trim(Crypt::decryptString($row->encrypted_value)); }
            catch (\Illuminate\Contracts\Encryption\DecryptException) { abort(503, 'Trainings-KI unvollständig konfiguriert.'); }
        }
        abort_unless(count(array_filter($settings)) === 3 && in_array($settings['OPENAI_TRAINING_MODEL'] ?? '', config('clubunio-mobile.training_ai.models', []), true), 503, 'Trainings-KI unvollständig konfiguriert.');
        ksort($settings, SORT_STRING);
        $reserve = (int) config('clubunio-mobile.training_ai.reservation_microusd', 0);
        $limit = min((int) config('clubunio-mobile.training_ai.monthly_limit_microusd', 0), (int) config('clubunio-mobile.ai.monthly_limit_microusd', 20_000_000));
        $tokens = (int) config('clubunio-mobile.training_ai.max_output_tokens', 0);
        $bytes = (int) config('clubunio-mobile.training_ai.max_input_bytes', 0);
        abort_unless($reserve > 0 && $limit >= $reserve && $tokens >= 1000 && $tokens <= 32000 && $bytes > 0 && $bytes <= 131072, 503, 'Geprüfte KI-Kostenbegrenzung fehlt.');
        return ['reserve' => $reserve, 'limit' => $limit, 'bytes' => $bytes, 'model' => $settings['OPENAI_TRAINING_MODEL'], 'provider' => $settings,
            'fingerprint' => hash('sha256', json_encode([$settings, $reserve, $tokens, $bytes, 'native-training-v1'], JSON_THROW_ON_ERROR))];
    }
    public function providerSnapshot(Tenant $tenant, string $fingerprint): array
    {
        $configuration = $this->configuration($tenant);
        abort_unless(hash_equals($configuration['fingerprint'], $fingerprint), 409);
        return $configuration['provider'];
    }
    public function reserve(Request $request, Account $actor, Tenant $tenant, string $operation)
    {
        $d = $request->validate(['request_key' => 'required|uuid', 'consent' => 'required|accepted', 'previous_work' => 'sometimes|nullable|uuid']);
        $config = $this->configuration($tenant);
        $service = app(MobileTrainingPlanService::class);
        $payload = ['session' => $request->attributes->get('mobile.session')->id,
            'season' => app(MobileSeasonScope::class)->current($tenant)?->id, 'configuration' => $config['fingerprint']];
        if ($operation === 'training_generate') {
            $service->authorizeOperation($actor, $tenant, 'create');
            $payload['input'] = $service->input($actor, $tenant, $request->all());
        } else {
            abort_unless($operation === 'training_review', 422);
            $v = $request->validate(['revision' => 'required|integer|min:1']);
            $row = $service->get($actor, $tenant, (int) $request->route('plan'), true, 'approve');
            $service->get($actor, $tenant, $row->id, true, 'update');
            abort_unless((int) $row->revision === $v['revision'], 409, 'Plan wurde geändert. Bitte neu laden.');
            [$draft, $changes] = app(MobileTrainingData::class)->changes($row, $request->all());
            $payload += ['plan_id' => (int) $row->id, 'revision' => (int) $v['revision'], 'draft' => $draft, 'input_changes' => $changes];
        }
        abort_unless(strlen(json_encode($payload, JSON_THROW_ON_ERROR)) <= $config['bytes'], 422, 'Trainingsentwurf ist zu gross.');
        // Session is rechecked by the executor, but is not part of logical request identity.
        $identity = $payload; unset($identity['session']);
        $fingerprint = hash('sha256', json_encode([$operation, $identity], JSON_THROW_ON_ERROR));
        $old = $this->table()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('request_key', $d['request_key'])->first();
        if ($old) {
            abort_unless($old->provider === 'openai_training' && $old->operation === $operation && hash_equals($old->fingerprint, $fingerprint), 409, 'Anfrageschlüssel gehört zu einem anderen Entwurf.');
            return response()->json(['data' => $this->read($actor, $tenant, $old->id)], 202);
        }
        // A restarted app can lose its local UUID. Reuse the same durable draft result,
        // including uncertain calls, rather than charging twice under a different UUID.
        $same = $this->table()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
            ->where('provider', 'openai_training')->where('operation', $operation)->where('fingerprint', $fingerprint)
            ->whereIn('state', ['queued','running','succeeded','attention','applied'])
            ->orderByRaw("case when state in ('queued','running','attention') then 0 else 1 end")
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $explicitNewPlan = $same && $operation === 'training_generate' && $same->state === 'succeeded'
            && ($d['previous_work'] ?? null) === $same->id;
        if ($same && ! $explicitNewPlan) { return response()->json(['data' => $this->read($actor, $tenant, $same->id)], 202); }
        $spent = (int) $this->table()->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('coalesce(sum(coalesce(actual_microusd,reserved_microusd)),0) as spent')->value('spent');
        abort_if($spent + $config['reserve'] > $config['limit'], 429, 'KI-Monatslimit erreicht.');
        abort_if($this->table()->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('provider', 'openai_training')
            ->whereIn('state', ['queued','running'])->exists(), 409, 'Ein Trainingsauftrag ist noch offen.');
        $id = (string) Str::uuid();
        $this->table()->insert(['id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $actor->id, 'request_key' => $d['request_key'],
            'fingerprint' => $fingerprint, 'provider' => 'openai_training', 'operation' => $operation, 'state' => 'queued',
            'revision' => $payload['revision'] ?? 0, 'reserved_microusd' => $config['reserve'], 'model' => $config['model'],
            'result_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))]);
        DB::connection('clubunio')->afterCommit(function () use ($id) {
            if (config('clubunio-mobile.training_ai.dispatch_after_response', true)) {
                app()->terminating(fn () => $this->execute($id));
            }
        });
        return response()->json(['data' => ['id' => $id, 'state' => 'queued']], 202);
    }
    private function owned(Account $actor, Tenant $tenant, string $id): object
    {
        $row = $this->table()->where('id', $id)->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->where('provider', 'openai_training')->first();
        abort_unless($row, 404);
        return $row;
    }
    private function source(object $work, array $payload): array
    {
        $session = DB::connection('clubunio')->table('clubora.mobile_sessions')->where('id', $payload['session'])->where('account_id', $work->account_id)
            ->whereNull('revoked_at')->where('expires_at', '>', now())->sharedLock()->first();
        abort_unless($session, 401);
        $actor = Account::whereKey($work->account_id)->where('active', true)->where('api_only', false)->firstOrFail();
        $tenant = Tenant::findOrFail($work->tenant_id);
        app(MobileAccess::class)->enter($actor, $tenant);
        abort_unless(config('clubunio-mobile.native_training_enabled', false), 503);
        $key = 'mobile.current_season.'.$tenant->id.'.'.now('Europe/Zurich')->toDateString();
        request()->attributes->remove($key);
        abort_unless((int) app(MobileSeasonScope::class)->current($tenant)?->id === (int) $payload['season'], 409);
        $config = $this->configuration($tenant);
        abort_unless(hash_equals($payload['configuration'], $config['fingerprint']), 409);
        $service = app(MobileTrainingPlanService::class);
        if ($work->operation === 'training_generate') {
            $service->authorizeOperation($actor, $tenant, 'create'); $service->input($actor, $tenant, $payload['input']);
        } else {
            $row = $service->get($actor, $tenant, $payload['plan_id'], true, 'approve');
            $service->get($actor, $tenant, $payload['plan_id'], true, 'update');
            abort_unless((int) $row->revision === (int) $payload['revision'], 409);
        }
        return [$actor, $tenant, $service];
    }
    public function execute(string $id): void
    {
        $work = $this->table()->where('id', $id)->where('provider', 'openai_training')->first();
        if (! $work || $work->state !== 'queued') { return; }
        $payload = json_decode(Crypt::decryptString($work->result_encrypted), true, 64, JSON_THROW_ON_ERROR);
        try {
            $claimed = DB::connection('clubunio')->transaction(function () use ($work, $payload) {
                Tenant::whereKey($work->tenant_id)->lockForUpdate()->firstOrFail();
                $this->source($work, $payload);
                return $this->table()->where('id', $work->id)->where('state', 'queued')->update(['state' => 'running']) === 1;
            });
            if (! $claimed) { return; }
            [$actor, $tenant, $service] = $this->source($work, $payload);
            $service->forWork($payload['configuration']);
            $authorization = fn () => $this->source($work, $payload);
            if ($work->operation === 'training_generate') {
                $id = $service->generate($actor, $tenant, $payload['input'], $work->request_key, $authorization);
                $result = ['plan_id' => $id];
            } else {
                $result = $service->reviewDraft($actor, $tenant, $payload['plan_id'], $payload['revision'], $work->request_key, $payload['draft'], $payload['input_changes'], $authorization);
            }
            DB::connection('clubunio')->transaction(function () use ($work, $payload, $result) {
                Tenant::whereKey($work->tenant_id)->lockForUpdate()->firstOrFail();
                $this->source($work, $payload);
                $this->table()->where('id', $work->id)->where('state', 'running')->update(['state' => 'succeeded',
                    'result_encrypted' => Crypt::encryptString(json_encode($payload + ['result' => $result], JSON_THROW_ON_ERROR))]);
            });
        } catch (\Throwable $error) {
            // An uncertain or failed paid call keeps its reservation and never automatically retries.
            $sent = isset($service) && $service->providerStarted();
            $this->table()->where('id', $work->id)->whereIn('state', ['queued','running'])->update([
                'state' => $sent ? 'attention' : 'rejected', 'actual_microusd' => $sent ? null : 0]);
            report(new \RuntimeException('Personal training work requires attention: '.$work->id));
        }
    }
    public function read(Account $actor, Tenant $tenant, string $id): array
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        $work = $this->owned($actor, $tenant, $id);
        $payload = json_decode(Crypt::decryptString($work->result_encrypted), true, 64, JSON_THROW_ON_ERROR);
        // Do not expose a completed result following revoked training access.
        app(MobileTrainingPlanService::class)->authorizeOperation($actor, $tenant, 'view');
        $data = ['id' => $work->id, 'state' => $work->state];
        if (in_array($work->state, ['queued','running'], true) && now()->diffInMinutes($work->created_at, true) > 10) { $data['state'] = 'attention'; }
        if ($work->state === 'succeeded' || $work->state === 'applied') {
            $service = app(MobileTrainingPlanService::class);
            if ($work->operation === 'training_generate' || $work->state === 'applied') {
                $row = $service->get($actor, $tenant, $payload['result']['plan_id']);
                $data['plan'] = app(MobileTrainingData::class)->plan($actor, $tenant, $row);
            } else {
                $row = $service->get($actor, $tenant, $payload['plan_id'], true, 'approve');
                abort_unless((int) $row->revision === (int) $payload['revision'] && time() < $payload['result']['expires'], 409, 'Vorschlag ist nicht mehr aktuell.');
                $data += ['proposal' => $work->id, 'plan' => array_merge(app(MobileTrainingData::class)->content($payload['result']['plan']), ['playerCount' => $payload['input_changes']['playerCount']])];
            }
        }
        return $data;
    }
    public function apply(Request $request, Account $actor, Tenant $tenant)
    {
        $d = $request->validate(['proposal' => 'required|uuid', 'revision' => 'required|integer|min:1']);
        $d['revision'] = (int) $d['revision'];
        $work = $this->owned($actor, $tenant, $d['proposal']);
        abort_unless($work->operation === 'training_review' && $work->state === 'succeeded', 409, 'Vorschlag wurde bereits verwendet oder ist nicht verfügbar.');
        $payload = json_decode(Crypt::decryptString($work->result_encrypted), true, 64, JSON_THROW_ON_ERROR);
        abort_unless((int) $request->route('plan') === $payload['plan_id'] && $d['revision'] === $payload['revision'] && time() < $payload['result']['expires'], 409);
        $service = app(MobileTrainingPlanService::class);
        $row = $service->get($actor, $tenant, $payload['plan_id'], true, 'approve');
        abort_unless((int) $row->revision === $d['revision'], 409, 'Plan wurde geändert. Bitte neu laden.');
        $service->save($actor, $tenant, $row->id, $d['revision'], $payload['result']['plan'], (bool) $row->shared, $payload['input_changes']);
        $payload['result'] = ['plan_id' => $row->id];
        $this->table()->where('id', $work->id)->update(['state' => 'applied', 'result_encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))]);
        return response()->json(['data' => app(MobileTrainingData::class)->plan($actor, $tenant, $service->get($actor, $tenant, $row->id))]);
    }
}
