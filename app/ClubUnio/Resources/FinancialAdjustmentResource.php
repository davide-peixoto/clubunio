<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\CreditNote;
use App\ClubUnio\Models\FinancialAdjustmentStatus;
use App\ClubUnio\Models\Refund;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

abstract class FinancialAdjustmentResource extends CatalogResource
{
    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-uturn-left';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label('Nr.')->sortable(),
            TextColumn::make('invoice_id')->label('Rechnung')->sortable()->url(fn ($record) => InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
            TextColumn::make('invoice.member.display_name')->label('Mitglied')->searchable(query: fn ($q, $search) => $q->whereHas('invoice.member', fn ($m) => $m->where(fn ($s) => $s->where('last_name', 'ilike', '%'.$search.'%')->orWhere('first_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('status.name')->label('Status')->badge()->color(fn ($state) => StatusAppearance::palette($state)),
            TextColumn::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'"))->sortable(),
            TextColumn::make('reason')->label('Begründung')->searchable()->limit(55)->wrap(),
            TextColumn::make('created_at')->label('Erstellt')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable(),
        ])->filters([SelectFilter::make('status_id')->label('Status')->options(fn () => FinancialAdjustmentStatus::where('tenant_id', Filament::getTenant()->id)->pluck('name', 'id'))])
            ->recordActions([ViewAction::make()->iconButton()->tooltip('Anzeigen')])->defaultSort('id', 'desc')->deferFilters(false)
            ->persistFiltersInSession()->persistSearchInSession();
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextEntry::make('id')->label('Nummer'), TextEntry::make('invoice_id')->label('Rechnung')->url(fn ($record) => InvoiceResource::getUrl('view', ['record' => $record->invoice_id])),
            TextEntry::make('invoice.member.display_name')->label('Mitglied'), TextEntry::make('status.name')->label('Status')->badge()->color(fn ($state) => StatusAppearance::palette($state)),
            TextEntry::make('amount_cents')->label('Betrag CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'")),
            TextEntry::make('revision')->label('Version'), TextEntry::make('reason')->label('Begründung')->columnSpanFull(),
            TextEntry::make('included_tax')->label('Enthaltene Steuer CHF')->placeholder('–')->visible(fn ($record) => $record instanceof CreditNote),
            TextEntry::make('external_reference')->label('Bexio-Referenz (manuell zugeordnet)')->placeholder('–')->visible(fn ($record) => $record instanceof CreditNote),
            TextEntry::make('proof_reference')->label('Rückerstattungsnachweis (manuell)')->placeholder('–')->visible(fn ($record) => $record instanceof Refund),
            TextEntry::make('confirmed_at')->label('Manuell bestätigt am')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->placeholder('–'),
            TextEntry::make('paid_on')->label('Nachweisdatum')->date('d.m.Y')->placeholder('–')->visible(fn ($record) => $record instanceof Refund),
            TextEntry::make('cancellation_reason')->label('Stornogrund')->placeholder('–')->columnSpanFull(),
        ]);
    }
}
