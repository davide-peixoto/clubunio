<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Resources\ExpenseResource;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PendingExpensesTable extends TableWidget
{
    protected static ?string $heading = 'Spesen in Bearbeitung';

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant && Filament::auth()->user()?->canAccessTenant($tenant), 403);

        return $table->query(Expense::where('tenant_id', $tenant->id)->where('active', true)->whereHas('status', fn ($q) => $q->whereIn('code', ['F', 'Z']))->with(['member', 'status', 'season', 'round'])->withSum('activeLines', 'amount'))
            ->columns([TextColumn::make('member.display_name')->label('Mitglied'), TextColumn::make('season.name')->label('Saison'), TextColumn::make('round.name')->label('Runde'), TextColumn::make('status.name')->label('Status')->badge(), TextColumn::make('active_lines_sum_amount')->label('Betrag')->money('CHF')])
            ->recordUrl(fn ($record) => ExpenseResource::getUrl('view', ['record' => $record]))->defaultSort('id', 'desc')->paginated([5, 10])->defaultPaginationPageOption(5);
    }
}
