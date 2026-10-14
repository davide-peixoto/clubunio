<?php

namespace App\ClubUnio\Resources\Relations;

class KeysRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'keys';

    protected static string $kind = 'keys';

    protected static ?string $title = 'Schlüssel';
}
