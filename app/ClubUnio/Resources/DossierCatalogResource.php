<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\DossierMaintenanceService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

abstract class DossierCatalogResource extends CatalogResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('revision')->default(0), TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160), Toggle::make('active')->label('Aktiv')->required()->default(true)]);
    }

    public static function table(Table $table): Table
    {
        $save = fn ($id, $data) => app(DossierMaintenanceService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), null, $id, $data);

        return $table->columns([TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable(), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([TernaryFilter::make('active')->label('Aktiv')])
            ->headerActions([CreateAction::make()->label('Hinzufügen')->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => $save(null, $data)))])
            ->recordActions([EditAction::make()->iconButton()->tooltip('Bearbeiten')->using(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => $save($record->id, $data)))])->persistFiltersInSession()->persistSearchInSession()->deferFilters(false)->defaultSort('name');
    }
}
