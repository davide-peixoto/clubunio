<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Qualification;

class QualificationResource extends DossierCatalogResource
{
    protected static ?string $model = Qualification::class;

    protected static ?string $modelLabel = 'Qualifikation';

    protected static ?string $pluralModelLabel = 'Qualifikationen';

    protected static ?string $tenantRelationshipName = 'qualifications';

    public static function getPages(): array
    {
        return ['index' => QualificationResource\Pages\ManageQualifications::route('/')];
    }
}
