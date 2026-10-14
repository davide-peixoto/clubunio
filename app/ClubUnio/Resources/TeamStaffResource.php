<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\TeamStaff;

class TeamStaffResource extends TeamAssignmentResource
{
    protected static ?string $model = TeamStaff::class;

    protected static ?string $modelLabel = 'Staff-Zuordnungen';

    protected static ?string $pluralModelLabel = 'Staff-Zuordnungen';

    protected static ?string $tenantRelationshipName = 'teamStaff';

    public static function getPages(): array
    {
        return ['index' => TeamStaffResource\Pages\ManageTeamStaff::route('/')];
    }
}
