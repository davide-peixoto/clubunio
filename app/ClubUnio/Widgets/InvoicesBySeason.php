<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class InvoicesBySeason extends ChartWidget
{
    protected ?string $heading = 'Rechnungsbeträge nach Saison';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = app(DashboardService::class)->invoicesBySeason(Filament::auth()->user(), Filament::getTenant());

        return ['labels' => array_column($rows, 'season'), 'datasets' => [
            ['label' => 'Gesamt CHF', 'data' => array_column($rows, 'amount'), 'backgroundColor' => '#164B8A'],
            ['label' => 'Offen CHF', 'data' => array_column($rows, 'open_amount'), 'backgroundColor' => StatusAppearance::hex('Offen')],
        ]];
    }
}
