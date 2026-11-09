<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\TrainingSlot;
use App\ClubUnio\Services\PitchWeeklyAreas;
use App\ClubUnio\Services\FacilityPlanningMode;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\{Get, Set};
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
        return TrainingOption::where('tenant_id', Filament::getTenant()->id)->where('active', true)->where('category', $category)->orderBy('sort_order')->localizedPluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        $neutral = FacilityPlanningMode::neutral(Filament::getTenant());
        $fields = [Hidden::make('revision'), Select::make('team_season_id')->label('Mannschaft / Saison')->options(fn () => static::options(TeamSeason::class, 'label'))->searchable()->required()];
        foreach (['period' => 'Sommer / Winter', 'weekday' => 'Wochentag', 'field' => 'Spielfeld', 'room' => 'Garderobe', 'side' => 'Anlagenseite'] as $category => $label) {
            $caption = $neutral ? (['field' => __('Bookable facility'), 'period' => __('Periode')][$category] ?? $label) : $label;
            $select = Select::make($category.'_id')->label($caption)
                ->options(fn () => static::choices($category))->required(in_array($category, ['period', 'weekday']) || ($neutral && $category === 'field'));
            if ($category === 'field') $select->live()->afterStateUpdated(fn (Set $set) => $set('area_id', $neutral ? null : 0));
            $fields[] = $select;
            if ($category === 'field') {
                $fields[] = Select::make('area_id')->label('Teilfläche')->placeholder(__($neutral ? 'Entire facility' : 'Ganzer Platz'))->default($neutral ? null : 0)
                    ->options(fn (Get $get) => app(PitchWeeklyAreas::class)->trainingOptions(Filament::getTenant(), $get('field_id') ?: null))
                    ->visible(fn () => app(PitchWeeklyAreas::class)->ready());
            }
        }
        $fields[] = TimePicker::make('starts_at')->label('Von')->seconds(false)->required();
        $fields[] = TimePicker::make('ends_at')->label('Bis')->seconds(false)->required();
        if (! $neutral) $fields[] = TextInput::make('hall')->label('Halle')->maxLength(255);
        foreach (['all_fields' => 'Alle Plätze verfügbar', 'without_main' => 'Ohne Hauptplatz', 'synthetic_only' => 'Nur Kunstrasen', 'website_visible' => 'Auf Webseite anzeigen', 'active' => 'Aktiv'] as $field => $label) {
            if (! $neutral || in_array($field, ['website_visible', 'active'], true)) {
                $fields[] = Toggle::make($field)->label($label)->default(in_array($field, ['all_fields', 'active']));
            }
        }

        return $schema->components($fields);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('teamSeason.label')->label('Mannschaft / Saison')->searchable(), TextColumn::make('period.name')->label('Periode'), TextColumn::make('weekday.name')->label('Tag'), TextColumn::make('starts_at')->label('Von')->sortable(), TextColumn::make('ends_at')->label('Bis'), TextColumn::make('field.name')->label('Platz'), TextColumn::make('room.name')->label('Garderobe'), TextColumn::make('hall')->label('Halle'), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([SelectFilter::make('team_season_id')->label('Mannschaft / Saison')->searchable()->options(fn () => static::options(TeamSeason::class, 'label')), SelectFilter::make('period_id')->label('Periode')->options(fn () => static::choices('period')), SelectFilter::make('weekday_id')->label('Tag')->options(fn () => static::choices('weekday')), TernaryFilter::make('active')->label('Aktiv')])->headerActions([static::createAction()])->recordActions([static::editAction(), static::copyAction(), static::deactivateAction()])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => TrainingSlotResource\Pages\ManageSlots::route('/')];
    }
}
