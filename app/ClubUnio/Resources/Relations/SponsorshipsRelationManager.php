<?php

namespace App\ClubUnio\Resources\Relations;

class SponsorshipsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'sponsorships';

    protected static string $kind = 'sponsorships';

    protected static ?string $title = 'Sponsoring';
}
