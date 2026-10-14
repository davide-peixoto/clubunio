<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\CatalogService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class CatalogResource extends Resource
{
    public static function getNavigationGroup(): ?string
    {
        return 'Stammdaten';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-adjustments-horizontal';
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && (Filament::auth()->user()?->canAccessTenant($tenant) ?? false);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny() && (int) $record->tenant_id === (int) Filament::getTenant()->id;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id ?? -1);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Bezeichnung')->required()->maxLength(160),
            TextInput::make('code')->label('Code')->required()->maxLength(80)->disabled(fn (?Model $record) => $record !== null)->dehydrated(fn (?Model $record) => $record === null),
            TextInput::make('sort_order')->label('Reihenfolge')->numeric()->default(0)->required()->minValue(0),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
        ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->label('Hinzufügen')->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(CatalogService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), null, $data)));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Bezeichnung')->searchable()->sortable(),
            TextColumn::make('code')->label('Code')->searchable(),
            TextColumn::make('sort_order')->label('Reihenfolge')->sortable(),
            IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([TernaryFilter::make('active')->label('Aktiv')])
            ->headerActions([static::createAction()])->recordActions([
                EditAction::make()->iconButton()->tooltip('Bearbeiten')->using(fn (Model $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(CatalogService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), $record->id, $data))),
            ])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()->defaultSort('sort_order');
    }
}
