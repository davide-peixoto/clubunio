<?php

namespace App\ClubUnio\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PlatformTenantMetrics
{
    public function overview(array $tenantIds): array
    {
        $members = app(MemberAccessService::class)->currentMembers()->whereIn('tenant_id', $tenantIds)
            ->selectRaw('tenant_id, count(*) as total')->groupBy('tenant_id')->pluck('total', 'tenant_id');
        $sync = $this->syncEvents()->whereIn('work.tenant_id', $tenantIds)
            ->addSelect('work.tenant_id')->groupBy('work.tenant_id')->get()->keyBy('tenant_id');

        $summary = [];
        foreach ($tenantIds as $id) {
            $summary[$id] = [
                'members' => (int) ($members[$id] ?? 0),
                'errors_7' => (int) ($sync[$id]->errors_7 ?? 0),
                'last_success' => $sync[$id]->last_success ?? null,
            ];
        }

        return $summary;
    }

    public function operations(int $tenantId): array
    {
        return $this->syncEvents()->where('work.tenant_id', $tenantId)
            ->addSelect('work.operation')->groupBy('work.operation')->orderBy('work.operation')->get()->all();
    }

    private function syncEvents(): Builder
    {
        $now = now();

        return DB::connection('clubunio')->table('clubunio.sync_work_items as work')
            ->leftJoin('clubunio.sync_work_events as event', 'event.work_item_id', '=', 'work.id')
            ->selectRaw("count(*) FILTER (WHERE event.event = 'attention' AND event.created_at >= ?) AS errors_7", [$now->copy()->subDays(7)])
            ->selectRaw("count(*) FILTER (WHERE event.event = 'attention' AND event.created_at >= ?) AS errors_30", [$now->copy()->subDays(30)])
            ->selectRaw("max(event.created_at) FILTER (WHERE event.event = 'succeeded') AS last_success");
    }
}
