<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\BillingRecipient;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

class BillingRecipientResource extends CatalogResource
{
    protected static ?string $model = BillingRecipient::class;

    protected static ?string $modelLabel = 'Rechnungsempfänger';

    protected static ?string $pluralModelLabel = 'Rechnungsempfänger';

    protected static ?string $tenantRelationshipName = 'billingRecipients';

    public static function form(Schema $schema): Schema
    {
        return parent::form($schema)->components(array_merge(parent::form($schema)->getComponents(), [
            Select::make('email_field')->label('Adressquelle')->options(['email' => 'Mitglied', 'mother_email' => 'Mutter', 'father_email' => 'Vater'])->required()->disabled(fn ($record) => $record !== null)->dehydrated(fn ($record) => $record === null),
        ]));
    }

    public static function getPages(): array
    {
        return ['index' => BillingRecipientResource\Pages\ManageBillingRecipients::route('/')];
    }
}
