<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Gender;

class GenderResource extends CatalogResource
{
    protected static ?string $model = Gender::class;

    protected static ?string $modelLabel = 'Geschlecht';

    protected static ?string $pluralModelLabel = 'Geschlechter';

    protected static ?string $tenantRelationshipName = 'genders';

    public static function getPages(): array
    {
        return ['index' => GenderResource\Pages\ManageGenders::route('/')];
    }
}
