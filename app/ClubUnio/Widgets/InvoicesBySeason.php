<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class InvoicesBySeason extends ChartWidget
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('invoices');
    }

    protected ?string $heading = 'Rechnungsbeträge nach Saison';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);
        $rows = app(DashboardService::class)->invoicesBySeason(Filament::auth()->user(), Filament::getTenant());

        return ['labels' => array_column($rows, 'season'), 'datasets' => [
            ['label' => 'Gesamt CHF', 'data' => array_column($rows, 'amount'), 'backgroundColor' => '#164B8A'],
            ['label' => 'Offen CHF', 'data' => array_column($rows, 'open_amount'), 'backgroundColor' => StatusAppearance::hex('Offen')],
        ]];
    }
}
