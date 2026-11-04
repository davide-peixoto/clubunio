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

    public function getHeading(): string
    {
        return __('Rechnungen nach Positionsstatus');
    }

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
        $colors = array_map(StatusAppearance::hex(...), $labels);
        // These are computed aggregate categories, not editable lookup records.
        $display = ['Ohne Position' => __('Ohne Position'), 'Teilbezahlt' => __('Teilbezahlt'),
            'Offen' => __('Offen'), 'Bezahlt' => __('Bezahlt'), 'Teilweise' => __('Teilweise')];

        return ['labels' => array_map(fn ($label) => $display[$label] ?? $label, $labels), 'datasets' => [['label' => __('Rechnungen'), 'data' => array_column($rows, 'total'), 'backgroundColor' => $colors]]];
    }
}
