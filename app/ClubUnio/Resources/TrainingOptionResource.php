<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\FacilityStatus;
use App\ClubUnio\Services\FacilityPlanningMode;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
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

    public const CATEGORIES = ['period' => 'Sommer / Winter', 'weekday' => 'Wochentag', 'field' => 'Spielfeld', 'room' => 'Garderobe', 'side' => 'Anlagenseite'];

    public static function form(Schema $schema): Schema
    {
        $neutral = FacilityPlanningMode::neutral(Filament::getTenant());
        $facility = fn (Get $get, $record) => $neutral && ($record?->category ?? $get('category')) === 'field';

        return $schema->components([Hidden::make('revision'), Select::make('category')->label('Kategorie')
            ->options(array_map(fn ($name) => __($name), self::categories()))->live(condition: $neutral)
            ->required()->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null),
            TextInput::make('code')->label('Code')->required()->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null),
            TextInput::make('name')->label('Bezeichnung')->required(),
            Select::make('facility_kind')->label(__('Facility type'))->options(array_map(fn ($name) => __($name), FacilityPlanningMode::KINDS))
                ->default('field')->visible($facility)->required(),
            Select::make('initial_status_id')->label(__('Initial facility status'))->options(fn () => static::options(FacilityStatus::class))
                ->visible(fn (Get $get, $record) => $record === null && $facility($get, $record))->required(),
            TextInput::make('sort_order')->label('Reihenfolge')->numeric()->default(0)->required(), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    private static function categories(): array
    {
        return FacilityPlanningMode::neutral(Filament::getTenant())
            ? array_replace(self::CATEGORIES, ['field' => 'Bookable facility', 'period' => 'Periode'])
            : self::CATEGORIES;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('category')->label('Kategorie')->formatStateUsing(fn ($state) => __(self::categories()[$state] ?? $state)), TextColumn::make('code')->label('Code'), TextColumn::make('name')->label('Bezeichnung')->searchable(),
            TextColumn::make('facility_kind')->label(__('Facility type'))->visible(fn () => FacilityPlanningMode::neutral(Filament::getTenant()))
                ->formatStateUsing(fn ($state) => __(FacilityPlanningMode::KINDS[$state] ?? $state)),
            IconColumn::make('active')->label('Aktiv')->boolean()])->filters([SelectFilter::make('category')->label('Kategorie')->options(array_map(fn ($name) => __($name), self::categories()))])->headerActions([static::createAction()])->recordActions([static::editAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => TrainingOptionResource\Pages\ManageOptions::route('/')];
    }
}
