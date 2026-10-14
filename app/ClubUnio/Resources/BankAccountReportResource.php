<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Bank;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BankAccountReportResource extends CatalogResource
{
    protected static ?string $model = MemberBankAccount::class;

    protected static ?string $pluralModelLabel = 'Bankkonten Staff';

    protected static ?string $tenantRelationshipName = 'memberBankAccounts';

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
        abort_unless(static::canViewAny(), 403);

        return parent::getEloquentQuery()->whereHas('member', fn ($q) => $q->whereNull('archived_at'))
            ->with(['member.status', 'bank']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn (Builder $query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q
                ->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('bank.name')->label('Bank')->searchable()->sortable()->placeholder('Keine Bank zugeordnet'),
            TextColumn::make('masked_iban')->label('IBAN'),
            TextColumn::make('bank.city')->label('Bankort')->toggleable(),
            TextColumn::make('bank.swift')->label('SWIFT')->toggleable(),
            TextColumn::make('bank.street')->label('Bankdomizil')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('bank.postal_address')->label('Bankpostadresse')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('bank.postal_code')->label('Bank-PLZ')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('bank.country_code')->label('Bank-Land')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('bank.clearing_number')->label('Clearing-Nr.')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('bank.postal_account')->label('Bankpostkonto')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('member.status.name')->label('Mitgliederstatus')->badge(),
            TextColumn::make('member.email')->label('E-Mail')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('member.street')->label('Strasse')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('member.postal_code')->label('PLZ')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('member.city')->label('Ort')->toggleable(isToggledHiddenByDefault: true),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([
            SelectFilter::make('bank_id')->label('Bank')->searchable()
                ->options(fn () => Bank::where('tenant_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id')),
            SelectFilter::make('staff_season')->label('Staff in Saison')->searchable()
                ->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->orderByDesc('name')->pluck('name', 'id'))
                ->query(function (Builder $query, array $data) {
                    $value = $data['value'] ?? null;
                    if ($value === null || $value === '') {
                        return $query;
                    }
                    if (! is_scalar($value) || ! ctype_digit((string) $value)
                        || ! Season::where('tenant_id', Filament::getTenant()->id)->whereKey($value)->exists()) {
                        return $query->whereRaw('1=0');
                    }

                    return $query->whereHas('member.teamStaff', fn ($q) => $q->where('active', true)
                        ->whereHas('teamSeason', fn ($q) => $q->where('active', true)->where('season_id', $value)));
                }),
            TernaryFilter::make('active')->label('Aktiv')->default(true),
        ])->headerActions([ReportExportService::action('banks')])->recordActions([
            ViewAction::make()->iconButton()->tooltip('Mitgliederdossier')
                ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id])),
        ])->defaultSort('id', 'desc')->paginated([25, 50, 100])
            ->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => BankAccountReportResource\Pages\ListBankAccounts::route('/')];
    }
}
