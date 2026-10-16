<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class NewMembersMonthly extends ChartWidget
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('members');
    }

    protected ?string $heading = 'Neue Mitglieder pro Monat';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);
        $data = app(DashboardService::class)->newMembersMonthly(Filament::auth()->user(), Filament::getTenant());

        return ['labels' => array_keys($data), 'datasets' => [['label' => 'Neu erfasst', 'data' => array_values($data), 'backgroundColor' => '#164B8A']]];
    }
}
