<?php

namespace App\ClubUnio\Resources\Relations;

class TeamStaffRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'teamStaff';

    protected static string $kind = 'teamStaff';

    protected static ?string $title = 'Staff';
}
