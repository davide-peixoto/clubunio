<?php

namespace App\ClubUnio\Resources\Relations;

class TeamSeasonsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'teamSeasons';

    protected static string $kind = 'teamSeasons';

    protected static ?string $title = 'Mannschaften pro Saison';
}
