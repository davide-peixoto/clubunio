<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\LegacyMaterialReceiptResource;
use Filament\Tables\Table;

class LegacyMaterialReceiptsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'legacyMaterialReceipts';

    protected static string $kind = 'legacyMaterialReceipts';

    protected static ?string $title = 'Historische Quittungen';

    public function table(Table $table): Table
    {
        return LegacyMaterialReceiptResource::table($table)->modifyQueryUsing(fn ($q) => $q->where('tenant_id', $this->getOwnerRecord()->tenant_id));
    }
}
