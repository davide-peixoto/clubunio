<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

final class SocialInsightsService
{
    public const METRICS = ['likes', 'comments', 'shares', 'saves', 'views', 'reach'];

    public function table(string $name)
    {
        abort_unless(in_array($name, ['connections','imported_posts','metric_snapshots','account_snapshots'], true), 422);
        return DB::connection('clubunio')->table('clubunio.social_'.$name);
    }

    public function authorize(?Account $actor, ?Tenant $tenant, string $function = 'view'): void
    {
        abort_unless($actor && $tenant && $actor->fresh()?->active && ! $actor->api_only
            && ! request()->attributes->has('clubunio_impersonation') && $actor->fresh()->canAccessTenant($tenant), 403);
        app(ModulePermissionService::class)->authorize($actor->fresh(), $tenant, 'social_media', $function);
    }

    public function enabled(): bool
    {
        return app()->environment('staging') && (bool) config('clubunio-social-insights.enabled') && in_array(rtrim(config('app.url'), '/'),
            ['https://dev.clubunio.ch','https://stage.clubunio.com'], true);
    }

    public function allowed(Tenant $tenant, string $platform, string $id): bool
    {
        return $this->enabled() && (string) $tenant->id === (string) config('clubunio-social-insights.test_tenant')
            && in_array($platform, ['facebook','instagram'], true) && preg_match('/\A[0-9]{1,80}\z/', $id)
            && in_array($id, config('clubunio-social-insights.'.$platform.'_ids', []), true);
    }

    public function connect(Account $actor, Tenant $tenant, string $platform, array $page, string $expires): int
    {
        $this->authorize($actor, $tenant, 'update');
        $id = (string) ($platform === 'instagram' ? data_get($page, 'instagram_business_account.id') : ($page['id'] ?? ''));
        abort_unless($this->allowed($tenant, $platform, $id) && is_string($page['access_token'] ?? null)
            && strlen($page['access_token']) >= 10 && now()->lt($expires), 403);
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $platform, $page, $expires, $id) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $key = ['tenant_id'=>$tenant->id,'platform'=>$platform,'external_id'=>$id];
            $previous = $this->table('connections')->where($key)->first();
            $this->table('connections')->updateOrInsert($key,
                ['name'=>mb_substr((string) ($platform === 'instagram' ? data_get($page,'instagram_business_account.username', $id) : ($page['name'] ?? $id)),0,200),
                    'token_encrypted'=>Crypt::encryptString($page['access_token']), 'expires_at'=>$expires,
                    'connected_by'=>$actor->id, 'active'=>true, 'revision'=>$previous ? $previous->revision + 1 : 1]);
            return (int) $this->table('connections')->where('tenant_id',$tenant->id)->where('platform',$platform)->where('external_id',$id)->value('id');
        });
    }

    public function disconnect(Account $actor, Tenant $tenant, int $id): void
    {
        $this->authorize($actor, $tenant, 'update');
        abort_unless($this->table('connections')->where('tenant_id',$tenant->id)->where('id',$id)->update([
            'active'=>false,'token_encrypted'=>null,'revision'=>DB::connection('clubunio')->raw('revision + 1'),
        ]),404);
    }

    public function enqueue(Account $actor, Tenant $tenant, int $id): int
    {
        $this->authorize($actor, $tenant, 'sync');
        $connection = $this->table('connections')->where('tenant_id',$tenant->id)->find($id);
        abort_unless($connection && $connection->active && $this->allowed($tenant,$connection->platform,$connection->external_id),403);
        $queue = app(SyncWorkService::class);
        $pending = $queue->table()->where('tenant_id',$tenant->id)->where('operation','social_insights')->where('object_id',$id)
            ->whereIn('state',['pending','running'])->value('id');
        return $pending ? (int) $pending : $queue->remember($actor,$tenant,'social_insights',$id,
            ['revision'=>$connection->revision,'request_id'=>(string) Str::uuid()]);
    }

    public function import(Account $actor, Tenant $tenant, int $id, int $revision): int
    {
        $this->authorize($actor,$tenant,'sync');
        $connection = $this->table('connections')->where('tenant_id',$tenant->id)->find($id);
        abort_unless($connection && $connection->active && $connection->token_encrypted
            && (int) $connection->revision === $revision && $this->allowed($tenant,$connection->platform,$connection->external_id),409);
        abort_unless(now()->lt($connection->expires_at),401);
        $token = Crypt::decryptString($connection->token_encrypted);
        $client = app(SocialInsightsClient::class);
        $identity = $client->get($token,$connection->external_id,['fields'=>$connection->platform === 'instagram' ? 'id,username,followers_count' : 'id,name,followers_count']);
        abort_unless((string) ($identity['id'] ?? '') === $connection->external_id,502);
        $posts = $client->posts($connection,$token);
        return DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$id,$revision,$posts,$identity) {
            $this->authorize($actor,$tenant,'sync');
            $current = $this->table('connections')->where('tenant_id',$tenant->id)->where('id',$id)->lockForUpdate()->first();
            abort_unless($current && $current->active && (int) $current->revision === $revision,409);
            $now = now()->toIso8601String(); $day = now('UTC')->toDateString();
            foreach ($posts as $post) {
                $metrics = json_encode($post['metrics'],JSON_THROW_ON_ERROR);
                $this->table('imported_posts')->updateOrInsert(['tenant_id'=>$tenant->id,'connection_id'=>$id,'external_id'=>$post['external_id']],
                    [...array_diff_key($post,['metrics'=>true]),'metrics'=>$metrics,'last_observed_at'=>$now]);
                $postId = $this->table('imported_posts')->where('tenant_id',$tenant->id)->where('connection_id',$id)->where('external_id',$post['external_id'])->value('id');
                $this->table('metric_snapshots')->updateOrInsert(['tenant_id'=>$tenant->id,'connection_id'=>$id,'post_id'=>$postId,'day'=>$day],['sampled_at'=>$now,'metrics'=>$metrics]);
            }
            $this->table('account_snapshots')->updateOrInsert(['tenant_id'=>$tenant->id,'connection_id'=>$id,'day'=>$day],
                ['sampled_at'=>$now,'followers'=>SocialInsightsClient::number($identity['followers_count'] ?? null)]);
            $this->table('connections')->where('tenant_id',$tenant->id)->where('id',$id)->update(['last_synced_at'=>$now]);
            return count($posts);
        });
    }

    public function tick(): int
    {
        if (! $this->enabled()) return 0;
        $queue = app(SyncWorkService::class);
        // Re-authorize the original actor; never run under an elevated technical identity.
        foreach ($this->table('connections')->where('active',true)->where(fn ($q)=>$q->whereNull('last_synced_at')->orWhere('last_synced_at','<',now()->subHours(6)))
            ->orderBy('id')->limit(10)->get() as $connection) {
            $actor = Account::find($connection->connected_by); $tenant = Tenant::find($connection->tenant_id);
            if (! $actor || ! $tenant || ! $this->allowed($tenant,$connection->platform,$connection->external_id)) continue;
            if ($queue->table()->where('tenant_id',$tenant->id)->where('operation','social_insights')->where('object_id',$connection->id)
                ->whereIn('state',['pending','running','attention'])->exists()) continue;
            try { $this->enqueue($actor,$tenant,$connection->id); } catch (\Throwable) { /* Revoked rights: do not schedule. */ }
        }
        $count = 0;
        foreach ($queue->table()->where('operation','social_insights')->where('state','pending')->orderBy('id')->limit(2)->get() as $item) {
            $actor = Account::find($item->created_by); $tenant = Tenant::find($item->tenant_id);
            if (! $actor || ! $tenant) continue;
            try { $queue->run($actor,$tenant,'social_insights',$item->object_id,json_decode(Crypt::decryptString($item->encrypted_payload),true,512,JSON_THROW_ON_ERROR)); }
            catch (\Throwable) { /* SyncWorkService retains the classified failure and audit trail. */ }
            $count++;
        }
        return $count;
    }
}
