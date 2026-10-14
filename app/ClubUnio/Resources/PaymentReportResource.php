<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\FinanceExportService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentReportResource extends ExpenseResource
{
    protected static ?string $pluralModelLabel = 'Spesen zur Bezahlung';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('active', true)->whereHas('status', fn ($q) => $q->where('code', 'Z'));
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns([...array_values($table->getColumns()),
            TextColumn::make('bankAccount.bank.name')->label('Bank')->placeholder('–'),
            TextColumn::make('bankAccount.masked_iban')->label('IBAN')->placeholder('–'),
            TextColumn::make('payment_released_on')->label('Zur Zahlung freigegeben')->date('d.m.Y')->sortable(),
        ])->headerActions([Action::make('export')->label('CSV exportieren')->icon('heroicon-o-arrow-down-tray')
            ->action(fn ($livewire) => app(FinanceExportService::class)->download(Filament::auth()->user(), Filament::getTenant(), 'payments', $livewire->getFilteredSortedTableQuery()))])
            ->recordActions([ViewAction::make()->iconButton()->tooltip('Spesenbeleg')
                ->url(fn ($record) => ExpenseResource::getUrl('view', ['record' => $record]))]);
    }

    public static function getPages(): array
    {
        return ['index' => PaymentReportResource\Pages\ListPayments::route('/')];
    }
}
