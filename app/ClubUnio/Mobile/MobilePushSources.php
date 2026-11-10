<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\DB;

final class MobilePushSources
{
    /** Durable600 source already exists; no text or arbitrary recipient argument. */
    public function challenge(Tenant $tenant, int $resetId): int
    {
        if (! config('clubunio-mobile-push.enabled', false)) return 0;
        $reset = DB::connection('clubunio')->table('clubunio.mobile_penalty_resets')->where('tenant_id', $tenant->id)->where('id', $resetId)->firstOrFail();
        $count = 0;
        foreach ($tenant->accounts()->where('active', true)->where('api_only', false)->get() as $actor) {
            if (! app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, 'penalty', $resetId)) continue;
            $count += $this->record($actor, $tenant, 'penalty', $resetId, 'penalty.reset:'.$reset->id, $reset->created_at);
        }
        return $count;
    }

    private function record(Account $actor, Tenant $tenant, string $kind, int $object, string $key, ?string $createdAt = null): int
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $kind, $object, $key, $createdAt) {
            if (! app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, $kind, $object)) return 0;
            DB::connection('clubunio')->table('clubunio.mobile_inbox')->insertOrIgnore(['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                'kind' => $kind, 'object_id' => $object, 'event_key' => $key, 'created_at' => now()->toIso8601String()]);
            return app(MobilePushOutbox::class)->enqueue($actor, $tenant, $kind, $object, $key, $createdAt);
        });
    }

    /** Reads only real notification identities, never legacy title/body or fake source IDs. */
    public function existingNotifications(): int
    {
        if (! config('clubunio-mobile-push.enabled', false)) return 0;
        $types = ['expense' => 'expense', 'registration' => 'registration', 'bus_reservation' => 'bus',
            'member_change_request' => 'member_change', 'team_event' => 'planning'];
        $db = DB::connection('clubunio'); $count = 0;
        // No historical flood on opt-in: source must postdate an active binding.
        $query = $db->table('clubunio.notifications as n')->whereIn('n.object_type', array_keys($types))
            ->where('n.created_at', '>', now()->subDay()->toIso8601String())->whereExists(function ($q) {
                $q->selectRaw('1')->from('clubunio.mobile_push_bindings as b')->whereColumn('b.account_id', 'n.account_id')
                    ->whereNull('b.revoked_at')->whereColumn('b.created_at', '<=', 'n.created_at');
            })->select(['n.id', 'n.tenant_id', 'n.account_id', 'n.object_type', 'n.object_id', 'n.created_at']);
        $query->chunkById(100, function ($rows) use (&$count, $types) {
            foreach ($rows as $row) {
                $actor = Account::whereKey($row->account_id)->where('active', true)->where('api_only', false)->first();
                $tenant = Tenant::find($row->tenant_id);
                if ($actor && $tenant && (int) $row->object_id > 0) {
                    $count += $this->record($actor, $tenant, $types[$row->object_type], (int) $row->object_id, 'notification:'.$row->id, $row->created_at);
                }
            }
        }, 'n.id', 'id');
        return $count;
    }

    public function challengeResets(): int
    {
        if (! config('clubunio-mobile-push.enabled', false) || ! config('clubunio-mobile.native_penalty_enabled', false)) return 0;
        $count = 0;
        DB::connection('clubunio')->table('clubunio.mobile_penalty_resets')->where('created_at', '>', now()->subDay()->toIso8601String())
            ->select(['id', 'tenant_id'])->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $tenant = Tenant::find($row->tenant_id);
                    if ($tenant) $count += $this->challenge($tenant, (int) $row->id);
                }
            });
        return $count;
    }
}
