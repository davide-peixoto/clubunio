<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\InvoiceTaxService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class FinanceResource extends CatalogResource
{
    protected static string $kind;

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
        return parent::getEloquentQuery()->with('tenant')->withSum('activeLines', static::$kind === 'invoices' ? 'net_amount' : 'amount');
    }

    public static function table(Table $table): Table
    {
        $table = DossierTables::configure($table, static::$kind);

        return $table->columns(array_merge([
            TextColumn::make('member.display_name')->label('Mitglied')->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id]))
                ->searchable(query: fn ($query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
        ], array_values($table->getColumns())))->persistFiltersInSession()->persistSearchInSession();
    }

    public static function infolist(Schema $schema): Schema
    {
        $items = [TextEntry::make('legacy_id')->label('Nummer'),
            TextEntry::make('member.display_name')->label('Mitglied')->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id])),
            TextEntry::make('season.name')->label('Saison'), TextEntry::make('status.name')->label('Status')->badge(),
            TextEntry::make(static::$kind === 'invoices' ? 'active_lines_sum_net_amount' : 'active_lines_sum_amount')->label('Betrag')
                ->state(fn ($record) => static::$kind === 'invoices' ? (float) $record->active_lines_sum_net_amount + (int) $record->rounding_cents / 100 : $record->active_lines_sum_amount)->money('CHF', locale: 'de_CH'),
            TextEntry::make('notes')->label('Bemerkungen')->placeholder('–')->columnSpanFull()];
        if (static::$kind === 'expenses') {
            $items[] = TextEntry::make('teamSeason.label')->label('Mannschaft');
            $items[] = TextEntry::make('round.name')->label('Runde');
            $items[] = TextEntry::make('bankAccount.bank.name')->label('Bank');
            $items[] = TextEntry::make('bankAccount.masked_iban')->label('IBAN');
            foreach (['released_on' => 'Freigegeben am', 'payment_released_on' => 'Zur Zahlung freigegeben am', 'paid_on' => 'Bezahlt am'] as $field => $label) {
                $items[] = TextEntry::make($field)->label($label)->date('d.m.Y')->placeholder('–');
            }
        } else {
            $items[] = TextEntry::make('category.name')->label('Kategorie')->placeholder('Nicht zugeordnet');
            $items[] = TextEntry::make('tax_mode')->label('Steuerbehandlung')->formatStateUsing(fn ($state) => $state === 'inclusive' ? 'Endpreise inklusive Steuer' : 'Steuerbefreit');
            $items[] = TextEntry::make('rounding_cents')->label('Rundung CHF')->formatStateUsing(fn ($state) => number_format($state / 100, 2, '.', "'"));
            $items[] = TextEntry::make('included_tax')->label('Enthaltene Steuer CHF')->state(fn ($record) => $record->tax_mode === 'inclusive' ? app(InvoiceTaxService::class)->summary($record)['tax'] : '0.00')->money('CHF', locale: 'de_CH');
            $items[] = TextEntry::make('workflowStatus.name')->label('Bearbeitungsstatus')->badge()->color(fn ($state) => StatusAppearance::palette($state))->placeholder('Importiert');
            $items[] = TextEntry::make('bexio_invoice_id')->label('Bexio-Nr.')->placeholder('–');
        }
        foreach (['paid_cents' => 'Bexio bezahlt', 'credit_cents' => 'Bexio Gutschriften', 'remaining_cents' => 'Bexio Restbetrag', 'overpaid_cents' => 'Bexio Guthaben', 'cash_received_cents' => 'Zahlungsliste Eingänge', 'cash_excess_cents' => 'Zahlungsliste Guthaben'] as $field => $label) {
            $items[] = TextEntry::make('bexioBalance.'.$field)->label($label)->placeholder('Noch nicht abgeglichen')
                ->formatStateUsing(fn ($state) => 'CHF '.number_format((int) $state / 100, 2, '.', "'"));
        }
        $items[] = TextEntry::make('bexioBalance.observed_at')->label('Finanzabgleich vom')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->placeholder('–');
        if (static::$kind === 'invoices') {
            $items[] = TextEntry::make('pending_credits')->label('ClubUnio Gutschriften offen CHF')->state(fn ($record) => number_format($record->creditNotes()->whereHas('status', fn ($q) => $q->whereIn('code', ['draft', 'released']))->sum('amount_cents') / 100, 2, '.', "'"));
            $items[] = TextEntry::make('pending_refunds')->label('Rückerstattungen vorgesehen CHF')->state(fn ($record) => number_format($record->refunds()->whereHas('status', fn ($q) => $q->where('code', 'draft'))->sum('amount_cents') / 100, 2, '.', "'"));
            $items[] = TextEntry::make('confirmed_refunds')->label('Rückerstattungen manuell bestätigt CHF')->state(fn ($record) => number_format($record->refunds()->whereHas('status', fn ($q) => $q->where('code', 'confirmed'))->sum('amount_cents') / 100, 2, '.', "'"));
            $items[] = TextEntry::make('available_cash')->label('Noch verfügbares Guthaben CHF')->state(fn ($record) => number_format(max(0, (int) ($record->bexioBalance?->cash_excess_cents ?? 0)
                - (int) $record->refunds()->whereHas('status', fn ($q) => $q->where('code', '<>', 'cancelled'))->sum('amount_cents')) / 100, 2, '.', "'"));
        }

        return $schema->columns(3)->components($items);
    }
}
