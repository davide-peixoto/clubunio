<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\InvoiceCategory;

class InvoiceCategoryResource extends CatalogResource
{
    protected static ?string $model = InvoiceCategory::class;

    protected static ?string $modelLabel = 'Rechnungskategorie';

    protected static ?string $pluralModelLabel = 'Rechnungskategorien';

    protected static ?string $tenantRelationshipName = 'invoiceCategories';

    public static function getPages(): array
    {
        return ['index' => InvoiceCategoryResource\Pages\ManageRecords::route('/')];
    }
}
