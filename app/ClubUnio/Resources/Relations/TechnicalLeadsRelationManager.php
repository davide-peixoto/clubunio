<?php

namespace App\ClubUnio\Resources\Relations;

class TechnicalLeadsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'technicalLeads';

    protected static string $kind = 'technicalLeads';

    protected static ?string $title = 'Technische Leitung';
}
