<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ExpenseStatus;

class ExpenseStatusResource extends CatalogResource
{
    protected static ?string $model = ExpenseStatus::class;

    protected static ?string $modelLabel = 'Spesenstatus';

    protected static ?string $pluralModelLabel = 'Spesenstatus';

    protected static ?string $tenantRelationshipName = 'expenseStatuses';

    public static function getPages(): array
    {
        return ['index' => ExpenseStatusResource\Pages\ManageRecords::route('/')];
    }
}
