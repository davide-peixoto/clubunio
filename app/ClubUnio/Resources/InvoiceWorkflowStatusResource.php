<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\InvoiceWorkflowStatus;

class InvoiceWorkflowStatusResource extends CatalogResource
{
    protected static ?string $model = InvoiceWorkflowStatus::class;

    protected static ?string $modelLabel = 'Rechnungs-Bearbeitungsstatus';

    protected static ?string $pluralModelLabel = 'Rechnungs-Bearbeitungsstatus';

    protected static ?string $tenantRelationshipName = 'invoiceWorkflowStatuses';

    public static function getPages(): array
    {
        return ['index' => InvoiceWorkflowStatusResource\Pages\ManageStatuses::route('/')];
    }
}
