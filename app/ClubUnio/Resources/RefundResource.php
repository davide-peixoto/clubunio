<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Refund;

class RefundResource extends FinancialAdjustmentResource
{
    protected static ?string $model = Refund::class;

    protected static ?string $modelLabel = 'Rückerstattung';

    protected static ?string $pluralModelLabel = 'Rückerstattungen';

    protected static ?string $tenantRelationshipName = 'refunds';

    public static function getPages(): array
    {
        return ['index' => RefundResource\Pages\ListRefunds::route('/'), 'view' => RefundResource\Pages\ViewRefund::route('/{record}')];
    }
}
