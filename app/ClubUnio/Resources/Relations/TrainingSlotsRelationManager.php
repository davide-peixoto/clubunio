<?php

namespace App\ClubUnio\Resources\Relations;

class TrainingSlotsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'trainingSlots';

    protected static string $kind = 'trainingSlots';

    protected static ?string $title = 'Trainingszeiten';
}
