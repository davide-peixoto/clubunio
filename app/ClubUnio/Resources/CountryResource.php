<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Country;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CountryResource extends CatalogResource
{
    protected static ?string $model = Country::class;

    protected static ?string $modelLabel = 'Land';

    protected static ?string $pluralModelLabel = 'Länder';

    protected static ?string $tenantRelationshipName = 'countries';

    public static function form(Schema $schema): Schema
    {
        return parent::form($schema)->components(array_merge(parent::form($schema)->getComponents(), [
            TextInput::make('iso3')->label('ISO3')->required()->maxLength(3)->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null),
        ]));
    }

    public static function getPages(): array
    {
        return ['index' => CountryResource\Pages\ManageCountries::route('/')];
    }
}
