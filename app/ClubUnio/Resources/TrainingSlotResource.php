<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\TrainingSlot;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TrainingSlotResource extends ContributionTrainingResource
{
    protected static ?string $model = TrainingSlot::class;

    protected static ?string $modelLabel = 'Trainingszeit';

    protected static ?string $pluralModelLabel = 'Trainingszeiten';

    protected static ?string $tenantRelationshipName = 'trainingSlots';

    public static function getNavigationGroup(): ?string
    {
        return 'Mannschaften';
    }

    public static function choices(string $category): array
    {
        return TrainingOption::where('tenant_id', Filament::getTenant()->id)->where('active', true)->where('category', $category)->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        $fields = [Hidden::make('revision'), Select::make('team_season_id')->label('Mannschaft / Saison')->options(fn () => static::options(TeamSeason::class, 'label'))->searchable()->required()];
        foreach (['period' => 'Sommer / Winter', 'weekday' => 'Wochentag', 'field' => 'Spielfeld', 'room' => 'Garderobe', 'side' => 'Seite'] as $category => $label) {
            $fields[] = Select::make($category.'_id')->label($label)->options(fn () => static::choices($category))->required(in_array($category, ['period', 'weekday']));
        }
        $fields[] = TimePicker::make('starts_at')->label('Von')->seconds(false)->required();
        $fields[] = TimePicker::make('ends_at')->label('Bis')->seconds(false)->required();
        $fields[] = TextInput::make('hall')->label('Halle')->maxLength(255);
        foreach (['all_fields' => 'Alle Plätze verfügbar', 'without_main' => 'Ohne Hauptplatz', 'synthetic_only' => 'Nur Kunstrasen', 'website_visible' => 'Auf Webseite anzeigen', 'active' => 'Aktiv'] as $field => $label) {
            $fields[] = Toggle::make($field)->label($label)->default(in_array($field, ['all_fields', 'active']));
        }

        return $schema->components($fields);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('teamSeason.label')->label('Mannschaft / Saison')->searchable(), TextColumn::make('period.name')->label('Periode'), TextColumn::make('weekday.name')->label('Tag'), TextColumn::make('starts_at')->label('Von')->sortable(), TextColumn::make('ends_at')->label('Bis'), TextColumn::make('field.name')->label('Platz'), TextColumn::make('room.name')->label('Garderobe'), TextColumn::make('hall')->label('Halle'), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([SelectFilter::make('team_season_id')->label('Mannschaft / Saison')->searchable()->options(fn () => static::options(TeamSeason::class, 'label')), SelectFilter::make('period_id')->label('Periode')->options(fn () => static::choices('period')), SelectFilter::make('weekday_id')->label('Tag')->options(fn () => static::choices('weekday')), TernaryFilter::make('active')->label('Aktiv')])->headerActions([static::createAction()])->recordActions([static::editAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => TrainingSlotResource\Pages\ManageSlots::route('/')];
    }
}
