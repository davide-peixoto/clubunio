<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class MembersByAge extends ChartWidget
{
    protected ?string $heading = 'Aktive Mitglieder nach Alter';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant && Filament::auth()->user()?->canAccessTenant($tenant), 403);
        $counts = app(DashboardService::class)->ages(Filament::auth()->user(), $tenant);

        return ['labels' => array_keys($counts), 'datasets' => [['label' => 'Mitglieder', 'data' => array_values($counts), 'backgroundColor' => '#48A9E8']]];
    }
}
