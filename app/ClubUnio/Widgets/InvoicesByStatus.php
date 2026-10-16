<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class InvoicesByStatus extends ChartWidget
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('invoices');
    }

    protected ?string $heading = 'Rechnungen nach Positionsstatus';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        abort_unless(static::canView(), 403);
        $rows = app(DashboardService::class)->invoicesByStatus(Filament::auth()->user(), Filament::getTenant());
        $labels = array_column($rows, 'status');

        return ['labels' => $labels, 'datasets' => [['label' => 'Rechnungen', 'data' => array_column($rows, 'total'), 'backgroundColor' => array_map(StatusAppearance::hex(...), $labels)]]];
    }
}
