<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\DB;

class DashboardReservationRequests
{
    public function pending(Account $actor, Tenant $tenant, int $busPage = 1, int $pitchPage = 1): array
    {
        abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant), 403);
        abort_unless(min($busPage, $pitchPage) >= 1 && max($busPage, $pitchPage) <= 1000000, 422);
        $permissions = app(ModulePermissionService::class);
        $groups = [];
        foreach (['bus' => ['bus', 'approve'], 'pitch' => ['competitions', 'update']] as $kind => [$module, $operation]) {
            if (! $permissions->allows($actor, $tenant, $module, 'view')
                || ! $permissions->allows($actor, $tenant, $module, $operation)) {
                continue;
            }
            $query = DB::connection('clubunio')->table('clubora.'.($kind === 'bus' ? 'bus_reservations' : 'pitch_requests'))
                ->where('tenant_id', $tenant->id);
            if ($kind === 'bus') {
                $query->where('active', true)->where('status', 'ANGEFRAGT');
            } else {
                $query->where('status', 'pending');
            }
            // Count separately: keep the list bounded without hiding older undecided requests.
            $count = (clone $query)->count();
            $page = min($kind === 'bus' ? $busPage : $pitchPage, max(1, (int) ceil($count / 5)));
            $groups[$kind] = ['count' => $count, 'page' => $page, 'has_more' => $page * 5 < $count, 'rows' => $query
                ->orderBy($kind === 'bus' ? 'starts_at' : 'date')->orderBy('id')->offset(($page - 1) * 5)->limit(5)
                ->get($kind === 'bus' ? ['id', 'starts_at', 'location'] : ['id', 'date', 'starts_at', 'opponent'])];
        }

        return $groups;
    }
}
