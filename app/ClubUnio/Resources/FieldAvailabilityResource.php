<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\FacilityStatus;
use App\ClubUnio\Models\FieldAvailability;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Facades\Filament;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\FacilityPlanningMode;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FieldAvailabilityResource extends FacilityResource
{
    protected static ?string $model = FieldAvailability::class;

    protected static ?string $modelLabel = 'Anlagenstatus';

    protected static ?string $pluralModelLabel = 'Anlagenstatus';

    protected static ?string $tenantRelationshipName = 'fieldAvailabilities';

    public static function form(Schema $schema): Schema
    {
        $restricted = fn ($record) => $record !== null && (FacilityPlanningMode::neutral(Filament::getTenant())
            || ! app(ModulePermissionService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'facilities', 'update'));
        return $schema->components([Hidden::make('revision'), Select::make('field_id')->label(fn () => __(FacilityPlanningMode::neutral(Filament::getTenant()) ? 'Bookable facility' : 'Spielfeld'))->options(fn () => TrainingSlotResource::choices('field'))->required()->disabled($restricted), Select::make('status_id')->label('Status')->options(fn () => static::options(FacilityStatus::class))->required(), Textarea::make('note')->label('Bemerkung')->maxLength(500), Toggle::make('active')->label('Aktiv')->default(true)->disabled($restricted)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('field.name')->label(fn () => __(FacilityPlanningMode::neutral(Filament::getTenant()) ? 'Bookable facility' : 'Spielfeld'))->searchable(), TextColumn::make('status.name')->label('Status')->badge(), TextColumn::make('note')->label('Bemerkung')->wrap(), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([TernaryFilter::make('active')->label('Aktiv')])->headerActions([
            static::createAction()->visible(fn () => ! FacilityPlanningMode::neutral(Filament::getTenant())),
            Action::make('view')->label(__('Manage bookable facilities'))->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn () => FacilityPlanningMode::neutral(Filament::getTenant()) && TrainingOptionResource::canViewAny())
                ->url(fn () => TrainingOptionResource::getUrl('index', ['filters' => ['category' => ['value' => 'field']]])),
        ])->recordActions([static::editAction()])->deferFilters(false);
    }

    public static function getPages(): array
    {
        return ['index' => FieldAvailabilityResource\Pages\ManageFields::route('/')];
    }
}
