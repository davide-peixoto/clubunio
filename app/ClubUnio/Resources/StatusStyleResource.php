<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\StatusStyle;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\StatusStyleService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class StatusStyleResource extends CatalogResource
{
    protected static ?string $model = StatusStyle::class;

    protected static ?string $modelLabel = 'Statusfarbe';

    protected static ?string $pluralModelLabel = 'Status- und Auswahlfarben';

    protected static ?string $tenantRelationshipName = 'statusStyles';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Hidden::make('revision'), TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160), ColorPicker::make('color')->label('Farbe')->hex()->required(), Toggle::make('active')->label('Aktiv')->default(true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable()->badge()->color(fn ($state) => StatusAppearance::palette($state)), ColorColumn::make('color')->label('Farbe'), IconColumn::make('active')->label('Aktiv')->boolean()])->filters([TernaryFilter::make('active')->label('Aktiv')])->headerActions([
            CreateAction::make()->label('Hinzufügen')->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(StatusStyleService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data))),
        ])->recordActions([
            EditAction::make()->iconButton()->tooltip('Bearbeiten')->using(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(StatusStyleService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
        ])->defaultSort('name')->deferFilters(false);
    }

    public static function getPages(): array
    {
        return ['index' => StatusStyleResource\Pages\ManageStyles::route('/')];
    }
}
