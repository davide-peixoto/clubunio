<?php

namespace App\ClubUnio\Resources\Relations;

use Livewire\Attributes\On;

trait RefreshFinancialDossier
{
    #[On('dossier-financial-updated')]
    public function refreshFinancialDossier(): void
    {
        $this->getOwnerRecord()->refresh();
        $this->flushCachedTableRecords();
    }
}
