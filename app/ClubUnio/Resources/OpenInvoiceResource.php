<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\DashboardService;
use App\ClubUnio\Services\FinanceExportService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OpenInvoiceResource extends InvoiceResource
{
    protected static ?string $pluralModelLabel = 'Offene Rechnungen';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function getEloquentQuery(): Builder
    {
        return app(DashboardService::class)->openInvoices(Filament::auth()->user(), Filament::getTenant())
            ->withSum('activeLines', 'net_amount');
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([...array_values($table->getColumns()),
            TextColumn::make('open_amount')->label('Offener Betrag')->money('CHF', locale: 'de_CH')->sortable(),
        ])->headerActions([Action::make('export')->label('CSV exportieren')->icon('heroicon-o-arrow-down-tray')
            ->action(fn ($livewire) => app(FinanceExportService::class)->download(Filament::auth()->user(), Filament::getTenant(), 'invoices', $livewire->getFilteredSortedTableQuery()))])
            ->recordActions([ViewAction::make()->iconButton()->tooltip('Rechnungsdetails')
                ->url(fn ($record) => InvoiceResource::getUrl('view', ['record' => $record]))]);
    }

    public static function getPages(): array
    {
        return ['index' => OpenInvoiceResource\Pages\ListOpenInvoices::route('/')];
    }
}
