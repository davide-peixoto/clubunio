<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\CustomerCredit;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CustomerCreditResource extends CatalogResource
{
    protected static ?string $model = CustomerCredit::class;

    protected static ?string $modelLabel = 'Kundenguthaben';

    protected static ?string $pluralModelLabel = 'Kundenguthaben';

    protected static ?string $tenantRelationshipName = 'customerCredits';

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
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
        return parent::getEloquentQuery()->with(['member', 'creditNote'])->withSum('allocations', 'amount_cents');
    }

    public static function columns(): array
    {
        return [TextColumn::make('external_reference')->label('Bexio-Gutschrift')->searchable(),
            TextColumn::make('member.display_name')->label('Mitglied')->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id])),
            TextColumn::make('amount_cents')->label('Manuell bestätigter Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('allocated_cents')->label('Bexio-verrechnet CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('remaining_cents')->label('Nicht verrechnet CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextColumn::make('creditNote.included_tax')->label('Originalsteuer CHF'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns(static::columns())->recordActions([ViewAction::make()->iconButton()->tooltip('Guthaben anzeigen')])
            ->filters([TernaryFilter::make('remaining')->label('Restguthaben')->queries(
                true: fn ($q) => $q->whereRaw('amount_cents > (select coalesce(sum(a.amount_cents),0) from clubora.customer_credit_allocations a where a.customer_credit_id=customer_credits.id)'),
                false: fn ($q) => $q->whereRaw('amount_cents = (select coalesce(sum(a.amount_cents),0) from clubora.customer_credit_allocations a where a.customer_credit_id=customer_credits.id)'),
                blank: fn ($q) => $q)])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('external_reference')->label('Bexio-Gutschrift'),
            TextEntry::make('member.display_name')->label('Mitglied'),
            TextEntry::make('credit_note_id')->label('Ursprüngliche Steuerkorrektur')->url(fn ($record) => CreditNoteResource::getUrl('view', ['record' => $record->credit_note_id])),
            TextEntry::make('amount_cents')->label('Manuell bestätigter Gesamtbetrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2)),
            TextEntry::make('allocated_cents')->label('Bexio-verrechnet CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2)),
            TextEntry::make('remaining_cents')->label('Nicht verrechnet (kein Bankguthaben) CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2)),
            TextEntry::make('creditNote.included_tax')->label('Steuerkorrektur CHF'),
            TextEntry::make('voucher_id')->label('Verifizierte Bexio-ID')->placeholder('Noch keine Verrechnung nachgewiesen'),
            TextEntry::make('revision')->label('Version'),
        ]);
    }

    public static function getRelations(): array
    {
        return [Relations\CustomerCreditAllocationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => CustomerCreditResource\Pages\ListCustomerCredits::route('/'), 'view' => CustomerCreditResource\Pages\ViewCustomerCredit::route('/{record}')];
    }
}
