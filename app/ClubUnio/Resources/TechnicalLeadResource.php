<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TechnicalLead;

class TechnicalLeadResource extends TeamAssignmentResource
{
    protected static ?string $model = TechnicalLead::class;

    protected static ?string $modelLabel = 'Technische Leiter';

    protected static ?string $pluralModelLabel = 'Technische Leiter';

    protected static ?string $tenantRelationshipName = 'technicalLeads';

    public static function getPages(): array
    {
        return ['index' => TechnicalLeadResource\Pages\ManageTechnicalLeads::route('/')];
    }
}
