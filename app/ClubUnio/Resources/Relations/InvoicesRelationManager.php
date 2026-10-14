<?php

namespace App\ClubUnio\Resources\Relations;

class InvoicesRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'invoices';

    protected static string $kind = 'invoices';

    protected static ?string $title = 'Rechnungen';
}
