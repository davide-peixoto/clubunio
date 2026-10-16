<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Services\DashboardService;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OpenInvoicesTable extends TableWidget
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('invoices');
    }

    protected static ?string $heading = 'Offene Rechnungen';

    public function table(Table $table): Table
    {
        abort_unless(static::canView(), 403);
        return $table->query(app(DashboardService::class)->openInvoices(Filament::auth()->user(), Filament::getTenant())->with(['member', 'season']))
            ->columns([TextColumn::make('legacy_id')->label('Nr.'), TextColumn::make('member.display_name')->label('Mitglied'), TextColumn::make('season.name')->label('Saison'), TextColumn::make('open_amount')->label('Offen')->money('CHF')])
            ->recordUrl(fn ($record) => InvoiceResource::getUrl('view', ['record' => $record]))->defaultSort('id', 'desc')->paginated([5, 10])->defaultPaginationPageOption(5);
    }
}
