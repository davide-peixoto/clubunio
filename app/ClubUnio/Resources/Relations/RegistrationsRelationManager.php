<?php

namespace App\ClubUnio\Resources\Relations;

class RegistrationsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'registrations';

    protected static string $kind = 'registrations';

    protected static ?string $title = 'Anmeldungen';
}
