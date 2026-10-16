<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

abstract class StatusDistribution extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    abstract protected function modelClass(): string;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);
        $tenant = Filament::getTenant();
        abort_unless(static::canView(), 403);
        $model = $this->modelClass();
        $record = new $model;
        $query = $model::where($record->qualifyColumn('tenant_id'), $tenant->id);
        if ($model === Member::class) {
            $query->whereNull($record->qualifyColumn('archived_at'));
        } else {
            $query->where($record->qualifyColumn('active'), true);
        }
        $statusTable = $record->status()->getRelated()->getTable();
        $statusId = $record->qualifyColumn('status_id');
        $rows = $query->leftJoin($statusTable.' as dashboard_status', fn ($join) => $join
            ->on('dashboard_status.id', '=', $statusId)->where('dashboard_status.tenant_id', $tenant->id))
            ->select($statusId, 'dashboard_status.name')->selectRaw('count(*) as total')
            ->groupBy($statusId, 'dashboard_status.name')->get();
        $labels = $rows->map(fn ($row) => $row->name ?? 'Ohne Status')->all();

        return ['labels' => $labels, 'datasets' => [['label' => 'Anzahl', 'data' => $rows->pluck('total')->all(),
            'backgroundColor' => array_map(StatusAppearance::hex(...), $labels)]]];
    }
}
