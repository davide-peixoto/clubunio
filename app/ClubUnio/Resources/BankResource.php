<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Bank;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BankResource extends CatalogResource
{
    protected static ?string $model = Bank::class;

    protected static ?string $modelLabel = 'Banken';

    protected static ?string $pluralModelLabel = 'Banken';

    protected static ?string $tenantRelationshipName = 'banks';

    public static function getPages(): array
    {
        return ['index' => BankResource\Pages\ManageRecords::route('/')];
    }

    public static function form(Schema $schema): Schema
    {
        return parent::form($schema)->components(array_merge(parent::form($schema)->getComponents(), [
            TextInput::make('city')->label('Ort')->maxLength(255),
            TextInput::make('swift')->label('SWIFT')->maxLength(20),
            TextInput::make('street')->label('Domizil')->maxLength(255),
            TextInput::make('postal_address')->label('Postadresse')->maxLength(255),
            TextInput::make('postal_code')->label('PLZ')->maxLength(30),
            TextInput::make('country_code')->label('Landcode')->maxLength(3),
            TextInput::make('clearing_number')->label('Clearing-Nr.')->maxLength(30),
            TextInput::make('postal_account')->label('Postkonto')->maxLength(60),
        ]));
    }
}
