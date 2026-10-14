<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DossierTables
{
    public static function configure(Table $table, string $kind): Table
    {
        $text = fn ($name, $label) => TextColumn::make($name)->label($label)->placeholder('–')->wrap();
        $money = fn ($name, $label) => $text($name, $label)->money('CHF', locale: 'de_CH');
        $filters = [TernaryFilter::make('active')->label('Aktiv')->default(true)];
        $actions = [];
        $columns = match ($kind) {
            'trainingSlots' => [$text('period.name', 'Periode'), $text('weekday.name', 'Wochentag'), $text('starts_at', 'Von'), $text('ends_at', 'Bis'), $text('field.name', 'Spielfeld'), $text('room.name', 'Garderobe'), $text('side.name', 'Seite'), $text('hall', 'Halle')],
            'teamSeasons' => [$text('team.name', 'Mannschaft')->searchable(), $text('season.name', 'Saison')->sortable()],
            'bankAccounts' => [$text('bank.name', 'Bank')->searchable(), $text('bank.city', 'Ort'), $text('masked_iban', 'IBAN')],
            'notes' => [$text('body', 'Bemerkung')->searchable()],
            'functions' => [$text('clubFunction.name', 'Funktion')->searchable(), $text('qualifications.name', 'Qualifikationen')->listWithLineBreaks()],
            'keys' => [$text('number', 'Schlüsselnummer')->searchable(), $text('name', 'Bezeichnung')->searchable(), $text('color', 'Farbe')],
            'sponsorships' => [$text('name', 'Sponsor')->searchable(), $money('amount', 'Betrag'), $text('expires_on', 'Laufzeit')->date('d.m.Y'), $text('notes', 'Bemerkungen')],
            'eventGuests' => [$text('clubEvent.name', 'Veranstaltung')->searchable(), $text('clubEvent.location', 'Ort'), $text('clubEvent.season.name', 'Saison')],
            'familyMemberships' => [$text('family.name', 'Familie')->searchable(), $money('discount', 'Rabatt'), $text('notes', 'Bemerkungen')],
            'documents' => [$text('original_filename', 'Dateiname')->searchable(), $text('documentType.name', 'Dokumententyp'), $text('file_size', 'Bytes')->numeric(), $text('source_created_at', 'Erstellt')->dateTime('d.m.Y H:i')],
            'registrations' => [$text('legacy_id', 'Nr.')->sortable(), $text('status.name', 'Status')->badge(), $text('submitted_at', 'Eingereicht')->dateTime('d.m.Y H:i'), $text('teamSeason.label', 'Mannschaft / Saison')],
            'teamPlayers','teamStaff','technicalLeads' => [$text('teamSeason.label', 'Mannschaft / Saison')->searchable()->sortable()],
            'invoices' => [$text('legacy_id', 'Rechnung-Nr.')->sortable()->searchable(), $text('season.name', 'Saison')->sortable(), $text('status.name', 'Status')->badge(), $money('active_lines_sum_net_amount', 'Betrag')->getStateUsing(fn ($record) => (float) $record->active_lines_sum_net_amount + (int) $record->rounding_cents / 100), $text('bexio_invoice_id', 'Bexio-Nr.')],
            'expenses' => [$text('legacy_id', 'Beleg-Nr.')->searchable()->sortable(), $text('teamSeason.label', 'Mannschaft / Saison')->searchable(), $text('round.name', 'Runde'), $text('status.name', 'Status')->badge(), $money('active_lines_sum_amount', 'Betrag')],
            'invoiceLines' => [$text('description', 'Bezeichnung')->searchable(), $money('gross_amount', 'Brutto'), $money('discount', 'Rabatt'), $money('net_amount', 'Endbetrag'), $text('tax_snapshot.code', 'Steuercode'), $text('status.name', 'Status')->badge(), $text('notes', 'Bemerkungen')],
            'expenseLines' => [$text('expenseType.name', 'Spesentyp'), $text('gameType.name', 'Spieltyp'), $text('opponent', 'Gegner')->searchable(), $money('amount', 'Betrag'), $money('suggested_amount', 'Vorschlag'),
                IconColumn::make('suggestion_accepted')->label('Prüfstatus')->boolean()->trueIcon('heroicon-s-check-circle')->falseIcon('heroicon-s-exclamation-circle')->trueColor('success')->falseColor('warning')
                    ->tooltip(fn ($record) => $record->suggestion_accepted === null ? 'Kein Prüfstatus vorhanden' : ($record->suggestion_accepted ? 'Vorschlagsbetrag übernommen' : 'Betrag vom Vorschlag abweichend')),
                $text('filename', 'Beleg'), $text('notes', 'Bemerkungen')],
        };
        if ($kind === 'teamStaff') {
            $columns[] = $text('staffFunction.name', 'Funktion');
        }
        if (in_array($kind, ['teamPlayers', 'teamStaff', 'technicalLeads', 'invoices', 'expenses'], true)) {
            $filters[] = SelectFilter::make('season')->label('Saison')->options(fn () => Models\Season::where('tenant_id', Filament::getTenant()->id)->orderByDesc('name')->pluck('name', 'id'))
                ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $value) => in_array($kind, ['invoices', 'expenses'], true) ? $q->where('season_id', $value) : $q->whereHas('teamSeason', fn ($s) => $s->where('season_id', $value))));
        }
        $statusModel = match ($kind) {
            'teamSeasons' => null,
            'invoices' => Models\InvoiceStatus::class,'expenses' => Models\ExpenseStatus::class,'registrations' => Models\RegistrationStatus::class, default => null
        };
        if ($kind === 'teamSeasons') {
            $actions[] = ViewAction::make()->iconButton()->tooltip('Mannschaft und Zuordnungen')->url(fn ($record) => TeamSeasonResource::getUrl('view', ['record' => $record]));
        }
        if ($statusModel) {
            $filters[] = SelectFilter::make('status_id')->label('Status')->options(fn () => $statusModel::where('tenant_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id'));
        }
        if (in_array($kind, ['invoices', 'expenses'], true)) {
            $resource = $kind === 'invoices' ? InvoiceResource::class : ExpenseResource::class;
            $actions[] = ViewAction::make()->iconButton()->tooltip('Details und Positionen')->url(fn ($record) => $resource::getUrl('view', ['record' => $record]));
        }
        $columns[] = IconColumn::make('active')->label('Aktiv')->boolean()->toggleable(isToggledHiddenByDefault: true);

        return $table->columns($columns)->filters($filters)->recordActions($actions)->headerActions([])->toolbarActions([])
            ->defaultSort('id', 'desc')->paginated([10, 25, 50])->deferFilters(false);
    }
}
