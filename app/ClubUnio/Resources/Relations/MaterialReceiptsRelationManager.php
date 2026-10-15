<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Resources\MaterialReceiptResource;
use Filament\Tables\Table;

class MaterialReceiptsRelationManager extends DossierRelationManager
{
    protected static string $relationship = 'materialReceipts';

    protected static string $kind = 'materialReceipts';

    protected static ?string $title = 'Materialübergaben';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return MaterialReceiptResource::table($table)->modifyQueryUsing(fn ($q) => $q->where('tenant_id', $this->getOwnerRecord()->tenant_id))
            ->headerActions([MaterialReceiptResource::createReceipt($this->getOwnerRecord()->id)->visible(! $this->getOwnerRecord()->archived_at)]);
    }
}
