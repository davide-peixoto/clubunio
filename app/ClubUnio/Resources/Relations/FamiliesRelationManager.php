<?php

namespace App\ClubUnio\Resources\Relations;

class FamiliesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'familyMemberships';

    protected static string $kind = 'familyMemberships';

    protected static ?string $title = 'Familien';
}
