<?php

namespace App\ClubUnio;

class WorkspaceRoutes
{
    public const DOCUMENTS = [
        'clubunio.material.pdf' => 'documents/material/{receipt}/pdf',
        'clubunio.material.history.pdf' => 'documents/material-history/{receipt}/pdf',
        'clubunio.invoices.preview' => 'documents/invoices/{invoice}/{source}/preview',
        'clubunio.invoices.pdf' => 'documents/invoices/{invoice}/{source}/pdf',
        'clubunio.expenses.document' => 'documents/expenses/{line}',
        'clubunio.documents.open' => 'documents/members/{document}',
        'clubunio.registrations.document' => 'documents/registrations/{document}',
    ];
}
