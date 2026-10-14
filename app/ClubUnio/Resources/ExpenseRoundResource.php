<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\ExpenseRound;

class ExpenseRoundResource extends CatalogResource
{
    protected static ?string $model = ExpenseRound::class;

    protected static ?string $modelLabel = 'Spesenrunden';

    protected static ?string $pluralModelLabel = 'Spesenrunden';

    protected static ?string $tenantRelationshipName = 'expenseRounds';

    public static function getPages(): array
    {
        return ['index' => ExpenseRoundResource\Pages\ManageRecords::route('/')];
    }
}
