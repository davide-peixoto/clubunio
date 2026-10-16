<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\ExpenseLine;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class ExpensesBySeason extends ChartWidget
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('expenses');
    }

    protected ?string $heading = 'Spesenbeträge nach Saison';

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
        $rows = ExpenseLine::query()->join('clubora.expenses as e', 'e.id', '=', 'clubora.expense_lines.expense_id')
            ->leftJoin('clubora.seasons as s', 's.id', '=', 'e.season_id')
            ->where('clubora.expense_lines.tenant_id', $tenant->id)->where('e.tenant_id', $tenant->id)
            ->where('clubora.expense_lines.active', true)->where('e.active', true)
            ->selectRaw("COALESCE(s.name, 'Ohne Saison') as season, COALESCE(sum(amount), 0) as total")
            ->groupBy('s.id', 's.name')->orderBy('s.name')->get();

        return ['labels' => $rows->pluck('season')->all(), 'datasets' => [['label' => 'CHF', 'data' => $rows->pluck('total')->map(fn ($n) => (float) $n)->all(), 'backgroundColor' => '#d97706']]];
    }
}
