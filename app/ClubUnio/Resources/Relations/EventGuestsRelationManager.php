<?php

namespace App\ClubUnio\Resources\Relations;

class EventGuestsRelationManager extends EditableDossierRelationManager
{
    protected static string $relationship = 'eventGuests';

    protected static string $kind = 'eventGuests';

    protected static ?string $title = 'Veranstaltungen';
}
