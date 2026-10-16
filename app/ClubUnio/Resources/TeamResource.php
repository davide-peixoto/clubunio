<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Team;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamResource extends CatalogResource
{
    protected static ?string $model = Team::class;

    protected static ?string $modelLabel = 'Mannschaft';

    protected static ?string $pluralModelLabel = 'Mannschaften';

    protected static ?string $tenantRelationshipName = 'teams';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Mannschaft')->required()->maxLength(160),
            Textarea::make('description')->label('Beschreibung')->maxLength(4000),
            TextInput::make('sfv_team_id')->label('SFV-Team-ID')->numeric()->minValue(1),
            TextInput::make('laundry_allowance')->label('Dressentschädigung')->numeric()->prefix('CHF')->minValue(0),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->columns([
            TextColumn::make('name')->label('Mannschaft')->searchable()->sortable()->url(fn ($record) => static::getUrl('view', ['record' => $record])),
            TextColumn::make('description')->label('Beschreibung')->wrap()->limit(80),
            TextColumn::make('sfv_team_id')->label('SFV-Team-ID')->sortable(),
            TextColumn::make('laundry_allowance')->label('Dressentschädigung')->money('CHF', locale: 'de_CH'),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => TeamResource\Pages\ManageTeams::route('/'), 'view' => TeamResource\Pages\ViewTeam::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\TeamSeasonsRelationManager::class, Relations\InvoicesRelationManager::class, Relations\ExpensesRelationManager::class];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([TextEntry::make('name')->label('Bezeichnung'), TextEntry::make('description')->label('Beschreibung'), TextEntry::make('sfv_team_id')->label('SFV-Team-ID'), TextEntry::make('laundry_allowance')->label('Dressentschädigung')]);
    }
}
