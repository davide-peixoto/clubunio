<?php

namespace App\ClubUnio\Resources\Relations;

class ExpensesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'expenses';

    protected static string $kind = 'expenses';

    protected static ?string $title = 'Spesen';
}
