<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\FinancialAdjustmentStatus;

class FinancialAdjustmentStatusResource extends CatalogResource
{
    protected static ?string $model = FinancialAdjustmentStatus::class;

    protected static ?string $modelLabel = 'Finanzkorrekturstatus';

    protected static ?string $pluralModelLabel = 'Finanzkorrekturstatus';

    protected static ?string $tenantRelationshipName = 'financialAdjustmentStatuses';

    public static function getPages(): array
    {
        return ['index' => FinancialAdjustmentStatusResource\Pages\ManageRecords::route('/')];
    }
}
