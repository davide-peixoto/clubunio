<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class MembersByAge extends ChartWidget
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('members');
    }

    protected ?string $heading = 'Aktive Mitglieder nach Alter';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);
        $tenant = Filament::getTenant();
        abort_unless(static::canView(), 403);
        $counts = app(DashboardService::class)->ages(Filament::auth()->user(), $tenant);

        return ['labels' => array_keys($counts), 'datasets' => [['label' => 'Mitglieder', 'data' => array_values($counts), 'backgroundColor' => '#48A9E8']]];
    }
}
