<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\StaffFunction;

class StaffFunctionResource extends CatalogResource
{
    protected static ?string $model = StaffFunction::class;

    protected static ?string $modelLabel = 'Staff-Funktionen';

    protected static ?string $pluralModelLabel = 'Staff-Funktionen';

    protected static ?string $tenantRelationshipName = 'staffFunctions';

    public static function getPages(): array
    {
        return ['index' => StaffFunctionResource\Pages\ManageStaffFunctions::route('/')];
    }
}
