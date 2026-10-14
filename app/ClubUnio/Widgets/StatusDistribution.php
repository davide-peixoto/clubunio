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
        $tenant = Filament::getTenant();
        abort_unless($tenant && Filament::auth()->user()?->canAccessTenant($tenant), 403);
        $model = $this->modelClass();
        $query = $model::where('tenant_id', $tenant->id);
        if ($model === Member::class) {
            $query->whereNull('archived_at');
        } else {
            $query->where('active', true);
        }
        $counts = $query->selectRaw('status_id, count(*) as total')->groupBy('status_id')->pluck('total', 'status_id');
        $statuses = (new $model)->status()->getRelated()->newQuery()->where('tenant_id', $tenant->id)->pluck('name', 'id');
        $labels = $counts->keys()->map(fn ($id) => $statuses[$id] ?? 'Ohne Status')->all();

        return ['labels' => $labels, 'datasets' => [['label' => 'Anzahl', 'data' => $counts->values()->all(),
            'backgroundColor' => array_map(StatusAppearance::hex(...), $labels)]]];
    }
}
