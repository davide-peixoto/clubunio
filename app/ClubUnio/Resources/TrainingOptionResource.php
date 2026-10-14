<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TrainingOption;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrainingOptionResource extends ContributionTrainingResource
{
    protected static ?string $model = TrainingOption::class;

    protected static ?string $modelLabel = 'Trainingsauswahl';

    protected static ?string $pluralModelLabel = 'Trainingsauswahl';

    protected static ?string $tenantRelationshipName = 'trainingOptions';

    public const CATEGORIES = ['period' => 'Sommer / Winter', 'weekday' => 'Wochentag', 'field' => 'Spielfeld', 'room' => 'Garderobe', 'side' => 'Seite'];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('revision'), Select::make('category')->label('Kategorie')->options(self::CATEGORIES)->required()->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null), TextInput::make('code')->label('Code')->required()->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null), TextInput::make('name')->label('Bezeichnung')->required(), TextInput::make('sort_order')->label('Reihenfolge')->numeric()->default(0)->required(), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('category')->label('Kategorie')->formatStateUsing(fn ($state) => self::CATEGORIES[$state] ?? $state), TextColumn::make('code')->label('Code'), TextColumn::make('name')->label('Bezeichnung')->searchable(), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([SelectFilter::make('category')->label('Kategorie')->options(self::CATEGORIES)])->headerActions([static::createAction()])->recordActions([static::editAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => TrainingOptionResource\Pages\ManageOptions::route('/')];
    }
}
