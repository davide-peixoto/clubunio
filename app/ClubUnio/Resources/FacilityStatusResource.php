<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\FacilityStatus;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FacilityStatusResource extends FacilityResource
{
    protected static ?string $model = FacilityStatus::class;

    protected static ?string $modelLabel = 'Anlagenstatus-Wert';

    protected static ?string $pluralModelLabel = 'Anlagenstatus-Werte';

    protected static ?string $tenantRelationshipName = 'facilityStatuses';

    public static function getNavigationGroup(): ?string
    {
        return 'Stammdaten';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('revision'), TextInput::make('code')->label('Code')->required()->maxLength(30)->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null), TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160), Toggle::make('blocks_training')->label('Für Training gesperrt')->default(false), TextInput::make('sort_order')->label('Reihenfolge')->integer()->default(0)->minValue(0)->required(), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Bezeichnung')->badge()->color(fn ($state) => StatusAppearance::palette($state))->searchable(), TextColumn::make('code')->label('Code'), IconColumn::make('blocks_training')->label('Gesperrt')->boolean(), IconColumn::make('active')->label('Aktiv')->boolean()])->headerActions([static::createAction()])->recordActions([static::editAction()])->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => FacilityStatusResource\Pages\ManageStatuses::route('/')];
    }
}
