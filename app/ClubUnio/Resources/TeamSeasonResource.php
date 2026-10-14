<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamSeason;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TeamSeasonResource extends TeamAssignmentResource
{
    protected static ?string $model = TeamSeason::class;

    protected static ?string $modelLabel = 'Mannschaft pro Saison';

    protected static ?string $pluralModelLabel = 'Mannschaften pro Saison';

    protected static ?string $tenantRelationshipName = 'teamSeasons';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('_version'),
            Select::make('team_id')->label('Mannschaft')->required()->searchable()->disabled(fn ($record) => $record !== null)->dehydrated(true)
                ->options(fn (?TeamSeason $record) => Team::where('tenant_id', Filament::getTenant()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $record?->team_id ?? -1))->orderBy('name')->pluck('name', 'id')),
            Select::make('season_id')->label('Saison')->required()->disabled(fn ($record) => $record !== null)->dehydrated(true)
                ->options(fn (?TeamSeason $record) => Season::where('tenant_id', Filament::getTenant()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $record?->season_id ?? -1))->orderByDesc('name')->pluck('name', 'id')),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('team.name')->label('Mannschaft')->searchable()->sortable()->url(fn ($record) => static::getUrl('view', ['record' => $record])),
            TextColumn::make('season.name')->label('Saison')->sortable(),
            TextColumn::make('legacy_id')->label('TeamSaison-ID')->sortable(),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([
            SelectFilter::make('season_id')->label('Saison')->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->orderByDesc('name')->pluck('name', 'id')),
            SelectFilter::make('team_id')->label('Mannschaft')->searchable()->options(fn () => Team::where('tenant_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id')),
            TernaryFilter::make('active')->label('Aktiv'),
        ])->headerActions([static::createAction()])->recordActions([static::editAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()->defaultSort('legacy_id', 'desc')->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => TeamSeasonResource\Pages\ListTeamSeasons::route('/'), 'view' => TeamSeasonResource\Pages\ViewTeamSeason::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\TeamPlayersRelationManager::class, Relations\TeamStaffRelationManager::class, Relations\TechnicalLeadsRelationManager::class, Relations\TrainingSlotsRelationManager::class, Relations\RegistrationsRelationManager::class, Relations\ExpensesRelationManager::class];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([TextEntry::make('team.name')->label('Mannschaft'), TextEntry::make('season.name')->label('Saison')]);
    }
}
