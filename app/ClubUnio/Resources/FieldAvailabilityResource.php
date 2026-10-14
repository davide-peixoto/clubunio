<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\FacilityStatus;
use App\ClubUnio\Models\FieldAvailability;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
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
        return $schema->components([Hidden::make('revision'), Select::make('field_id')->label('Spielfeld')->options(fn () => TrainingSlotResource::choices('field'))->required(), Select::make('status_id')->label('Status')->options(fn () => static::options(FacilityStatus::class))->required(), Textarea::make('note')->label('Bemerkung')->maxLength(500), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('field.name')->label('Spielfeld')->searchable(), TextColumn::make('status.name')->label('Status')->badge(), TextColumn::make('note')->label('Bemerkung')->wrap(), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([TernaryFilter::make('active')->label('Aktiv')])->headerActions([static::createAction()])->recordActions([static::editAction()])->deferFilters(false);
    }

    public static function getPages(): array
    {
        return ['index' => FieldAvailabilityResource\Pages\ManageFields::route('/')];
    }
}
