<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Season;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SeasonResource extends CatalogResource
{
    protected static ?string $model = Season::class;

    protected static ?string $modelLabel = 'Saison';

    protected static ?string $pluralModelLabel = 'Saisons';

    protected static ?string $tenantRelationshipName = 'seasons';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label('Saison')->required()->maxLength(100),
            DatePicker::make('starts_on')->label('Beginn'), DatePicker::make('ends_on')->label('Ende'),
            Toggle::make('active')->label('Aktiv')->default(true)->required()]);
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->columns([TextColumn::make('name')->label('Saison')->searchable()->sortable()->url(fn ($record) => static::getUrl('view', ['record' => $record])), IconColumn::make('active')->label('Aktiv')->boolean()])->defaultSort('name', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => SeasonResource\Pages\ManageSeasons::route('/'), 'view' => SeasonResource\Pages\ViewSeason::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\TeamSeasonsRelationManager::class, Relations\InvoicesRelationManager::class, Relations\ExpensesRelationManager::class];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([TextEntry::make('name')->label('Bezeichnung')]);
    }
}
