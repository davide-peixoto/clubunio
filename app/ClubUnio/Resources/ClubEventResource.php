<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ClubEvent;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClubEventResource extends DossierCatalogResource
{
    protected static ?string $model = ClubEvent::class;

    protected static ?string $modelLabel = 'Veranstaltung';

    protected static ?string $pluralModelLabel = 'Veranstaltungen';

    protected static ?string $tenantRelationshipName = 'clubEvents';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function form(Schema $schema): Schema
    {
        $options = fn ($model, $field) => fn ($record) => $model::where('tenant_id', Filament::getTenant()->id)->where(fn ($q) => $q->where('active', true)->orWhere('id', $record?->$field ?? -1))->orderBy('name')->pluck('name', 'id');

        return $schema->components([Hidden::make('revision')->default(0), TextInput::make('name')->label('Veranstaltung')->required()->maxLength(255), TextInput::make('location')->label('Ort')->required()->maxLength(255),
            Select::make('season_id')->label('Saison')->required()->options($options(Season::class, 'season_id')), Select::make('team_id')->label('Mannschaft')->searchable()->options($options(Team::class, 'team_id')), Toggle::make('active')->label('Aktiv')->default(true)->required()]);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->columns([TextColumn::make('name')->label('Veranstaltung')->searchable()->url(fn ($record) => static::getUrl('view', ['record' => $record])), TextColumn::make('location')->label('Ort')->searchable(), TextColumn::make('season.name')->label('Saison')->sortable(), TextColumn::make('team.name')->label('Mannschaft')])
            ->filters([SelectFilter::make('season_id')->label('Saison')->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->orderByDesc('name')->pluck('name', 'id')), TernaryFilter::make('active')->label('Aktiv')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([TextEntry::make('name')->label('Veranstaltung'), TextEntry::make('location')->label('Ort'), TextEntry::make('season.name')->label('Saison'), TextEntry::make('team.name')->label('Mannschaft')]);
    }

    public static function getPages(): array
    {
        return ['index' => ClubEventResource\Pages\ManageEvents::route('/'), 'view' => ClubEventResource\Pages\ViewEvent::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\EventAttendeesRelationManager::class];
    }
}
