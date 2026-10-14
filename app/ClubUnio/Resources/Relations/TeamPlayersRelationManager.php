<?php

namespace App\ClubUnio\Resources\Relations;

class TeamPlayersRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'teamPlayers';

    protected static string $kind = 'teamPlayers';

    protected static ?string $title = 'Spieler';
}
