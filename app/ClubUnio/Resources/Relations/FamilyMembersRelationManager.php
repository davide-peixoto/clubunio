<?php

namespace App\ClubUnio\Resources\Relations;

class FamilyMembersRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'memberships';

    protected static string $kind = 'familyMemberships';

    protected static ?string $title = 'Familienmitglieder';
}
