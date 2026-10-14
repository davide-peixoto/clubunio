<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\RegistrationStatus;

class RegistrationStatusResource extends CatalogResource
{
    protected static ?string $model = RegistrationStatus::class;

    protected static ?string $modelLabel = 'Anmeldestatus';

    protected static ?string $pluralModelLabel = 'Anmeldestatus';

    protected static ?string $tenantRelationshipName = 'registrationStatuses';

    public static function getPages(): array
    {
        return ['index' => RegistrationStatusResource\Pages\ManageRegistrationStatuses::route('/')];
    }
}
