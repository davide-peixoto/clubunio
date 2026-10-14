<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\CreditNote;

class CreditNoteResource extends FinancialAdjustmentResource
{
    protected static ?string $model = CreditNote::class;

    protected static ?string $modelLabel = 'Gutschrift';

    protected static ?string $pluralModelLabel = 'Gutschriften';

    protected static ?string $tenantRelationshipName = 'creditNotes';

    public static function getPages(): array
    {
        return ['index' => CreditNoteResource\Pages\ListCreditNotes::route('/'), 'view' => CreditNoteResource\Pages\ViewCreditNote::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\CreditNoteLinesRelationManager::class];
    }
}
