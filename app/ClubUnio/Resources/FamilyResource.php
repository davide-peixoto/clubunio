<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\CatalogModel;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Services\FamilyService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FamilyResource extends CatalogResource
{
    protected static ?string $model = Family::class;

    protected static ?string $modelLabel = 'Familie';

    protected static ?string $pluralModelLabel = 'Familien';

    protected static ?string $tenantRelationshipName = 'families';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Hidden::make('_version'), TextInput::make('name')->label('Familienname')->required()->maxLength(160),
            TextInput::make('street')->label('Strasse')->maxLength(255), TextInput::make('postal_code')->label('PLZ')->maxLength(20),
            TextInput::make('city')->label('Ort')->maxLength(255),
            Select::make('country_id')->label('Land')->searchable()->options(fn (?Family $record) => MemberResource::lookupOptions(Country::class, $record?->country_id)),
            Toggle::make('active')->label('Aktiv')->default(true)->required(),
        ]);
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->label('Hinzufügen')->slideOver()->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(FamilyService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), null, $data)));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()->slideOver()->iconButton()->tooltip('Bearbeiten')
            ->mutateRecordDataUsing(fn (array $data, CatalogModel $record) => array_merge($data, ['_version' => FamilyService::version($record)]))
            ->using(fn (CatalogModel $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(FamilyService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), $record->id, $data)));
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Familie')->searchable()->sortable()->url(fn ($record) => static::getUrl('view', ['record' => $record])), TextColumn::make('street')->label('Strasse'),
            TextColumn::make('postal_code')->label('PLZ'), TextColumn::make('city')->label('Ort')->searchable(),
            TextColumn::make('country.name')->label('Land'), IconColumn::make('active')->label('Aktiv')->boolean(),
        ])->filters([TernaryFilter::make('active')->label('Aktiv')->default(true)])
            ->headerActions([static::createAction()])->recordActions([static::editAction()])
            ->defaultSort('name')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => FamilyResource\Pages\ManageFamilies::route('/'), 'view' => FamilyResource\Pages\ViewFamily::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\FamilyMembersRelationManager::class];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([TextEntry::make('name')->label('Bezeichnung'), TextEntry::make('street')->label('Strasse'), TextEntry::make('postal_code')->label('PLZ'), TextEntry::make('city')->label('Ort'), TextEntry::make('country.name')->label('Land')]);
    }
}
