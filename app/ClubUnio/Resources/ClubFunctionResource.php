<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ClubFunction;

class ClubFunctionResource extends DossierCatalogResource
{
    protected static ?string $model = ClubFunction::class;

    protected static ?string $modelLabel = 'Vereinsfunktion';

    protected static ?string $pluralModelLabel = 'Vereinsfunktionen';

    protected static ?string $tenantRelationshipName = 'clubFunctions';

    public static function getPages(): array
    {
        return ['index' => ClubFunctionResource\Pages\ManageFunctions::route('/')];
    }
}
