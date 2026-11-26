<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Http, Validator};
use Illuminate\Validation\ValidationException;

final class TenantAiConfiguration
{
    public function table(string $name = 'connections')
    {
        abort_unless(in_array($name, ['connections','stores','documents','usage','events'], true), 500);
        return DB::connection('clubunio')->table('clubunio.tenant_ai_'.$name);
    }

    public function authorize(?Account $actor, Tenant $tenant): void
    {
        abort_unless(config('clubunio-tenant-ai.enabled') && $actor && !$actor->api_only
            && BexioConnectionService::canManage($actor, $tenant), 403);
        abort_if(request()->hasSession() && request()->session()->has(ImpersonationService::KEY), 403);
    }

    public function summary(Tenant $tenant): array
    {
        $row = $this->table()->where('tenant_id', $tenant->id)->first();
        return ['mode' => $row->mode ?? 'own', 'project_id' => $row->project_id ?? '',
            'key_present' => !empty($row->encrypted_key), 'enabled' => (bool) ($row->enabled ?? false),
            'privacy_reviewed' => (bool) ($row->privacy_reviewed ?? false),
            'monthly_limit_microusd' => (int) ($row->monthly_limit_microusd ?? 30_000_000),
            'revision' => (int) ($row->revision ?? 0), 'verified' => !empty($row->verified_fingerprint)];
    }

    private function configurationAccess(Account $actor, Tenant $tenant, bool $platform): void
    {
        if (!$platform) { $this->authorize($actor,$tenant); return; }
        abort_unless(config('clubunio-tenant-ai.enabled') && app(PlatformAdministrationService::class)->allowed($actor),403);
        abort_if(request()->hasSession() && request()->session()->has(ImpersonationService::KEY),403);
    }

    public function save(Account $actor, Tenant $tenant, array $input, bool $platformProvisioning = false): void
    {
        $this->configurationAccess($actor, $tenant, $platformProvisioning);
        abort_if($platformProvisioning && ($input['mode'] ?? '')!=='clubunio',403);
        $data = Validator::make($input, ['mode' => 'required|in:own,clubunio',
            'project_id' => ['nullable','string','regex:/^proj_[A-Za-z0-9_-]{1,90}$/D'],
            'api_key' => ['nullable','string','max:1024','regex:/^sk-[A-Za-z0-9_-]{10,1000}$/D'],
            'enabled' => 'required|boolean', 'privacy_reviewed' => 'required|boolean',
            'monthly_limit_microusd' => 'required|integer|min:1|max:1000000000', 'revision' => 'required|integer|min:0'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data, $platformProvisioning) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->configurationAccess($actor, $tenant, $platformProvisioning);
            $old = $this->table()->where('tenant_id', $tenant->id)->first();
            abort_unless((int) ($old->revision ?? 0) === (int) $data['revision'], 409);
            $platform = app(PlatformAdministrationService::class)->allowed($actor);
            $project = $data['project_id'] ?: null;
            $newKey = trim($data['api_key'] ?? '');
            if (!$platform && ($data['mode'] === 'clubunio' || ($old->mode ?? '') === 'clubunio')) {
                abort_if($newKey !== '' || $project !== ($old->project_id ?? null), 403);
                abort_if($old && (int)$data['monthly_limit_microusd'] > (int)$old->monthly_limit_microusd,403);
            }
            $switch = $old && ($project !== $old->project_id || $old->mode !== $data['mode']);
            abort_if($switch && $this->table('stores')->where('tenant_id', $tenant->id)->where('state','!=','archived')->exists(), 409, trans('tenant_ai.project_locked'));
            $encrypted = $newKey !== '' ? Crypt::encryptString($newKey) : ($switch ? null : ($old->encrypted_key ?? null));
            $hash = $newKey !== '' ? hash('sha256', $newKey) : ($switch ? null : ($old->key_hash ?? null));
            abort_if(($project && $this->table()->where('tenant_id','!=',$tenant->id)->where('project_id',$project)->exists())
                || ($hash && $this->table()->where('tenant_id','!=',$tenant->id)->where('key_hash',$hash)->exists()), 422, trans('tenant_ai.project_assigned'));
            $fingerprint = $project && $hash ? hash('sha256', $project.'|'.$hash) : null;
            $verified = $fingerprint && hash_equals($old->verified_fingerprint ?? '', $fingerprint) ? $fingerprint : null;
            if ($data['enabled'] && (!$verified || !$data['privacy_reviewed'])) {
                throw ValidationException::withMessages(['enabled' => trans('tenant_ai.verify_first')]);
            }
            $this->table()->updateOrInsert(['tenant_id' => $tenant->id], ['mode' => $data['mode'], 'project_id' => $project,
                'encrypted_key' => $encrypted, 'key_hash' => $hash, 'enabled' => (bool) $data['enabled'],
                'privacy_reviewed' => (bool) $data['privacy_reviewed'], 'monthly_limit_microusd' => $data['monthly_limit_microusd'],
                'verified_fingerprint' => $verified, 'revision' => $data['revision'] + 1, 'updated_at' => now()]);
            $this->event($tenant, $actor, 'configuration.saved');
        });
    }

    public function credentials(Tenant $tenant, bool $requireEnabled = true): array
    {
        abort_unless(config('clubunio-tenant-ai.enabled'), 503);
        $row = $this->table()->where('tenant_id', $tenant->id)->first();
        abort_unless($row && $row->project_id && $row->encrypted_key, 503, trans('tenant_ai.not_ready'));
        abort_if($requireEnabled && (!$row->enabled || !$row->privacy_reviewed || !$row->verified_fingerprint
            || !hash_equals($row->verified_fingerprint,hash('sha256',$row->project_id.'|'.$row->key_hash))), 503, trans('tenant_ai.not_ready'));
        return ['key' => Crypt::decryptString($row->encrypted_key), 'project' => $row->project_id,
            'fingerprint' => hash('sha256', $row->project_id.'|'.$row->key_hash), 'revision' => (int) $row->revision,
            'mode' => $row->mode, 'limit' => (int) $row->monthly_limit_microusd];
    }

    public function verify(Account $actor, Tenant $tenant, bool $platformProvisioning = false): void
    {
        $this->configurationAccess($actor, $tenant, $platformProvisioning);
        $credentials = $this->credentials($tenant, false);
        $response = Http::withToken($credentials['key'])->withHeaders(['OpenAI-Project' => $credentials['project']])
            ->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()->get('https://api.openai.com/v1/models');
        abort_unless($response->successful() && is_array($response->json('data')), 502, trans('tenant_ai.connection_failed'));
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $credentials, $platformProvisioning) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->configurationAccess($actor, $tenant, $platformProvisioning);
            abort_unless($this->credentials($tenant, false)['fingerprint'] === $credentials['fingerprint'], 409);
            $this->table()->where('tenant_id', $tenant->id)->update(['verified_fingerprint' => $credentials['fingerprint']]);
            $this->event($tenant, $actor, 'connection.verified');
        });
    }

    public function event(Tenant $tenant, Account $actor, string $action, ?int $id = null): void
    {
        $this->table('events')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'action' => $action, 'object_id' => $id]);
    }

    public function usage(Account $actor, Tenant $tenant): array
    {
        $this->authorize($actor, $tenant);
        return $this->table('usage')->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('task, mode, count(*) as requests, sum(coalesce(actual_microusd,reserved_microusd)) as cost_microusd, sum(search_calls) as search_calls')
            ->groupBy('task','mode')->get()->map(fn ($r) => (array) $r)->all();
    }
}
