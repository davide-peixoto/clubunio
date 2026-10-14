<?php

namespace App\ClubUnio\Models\Concerns;

trait UsesClubUnioSchema
{
    public function initializeUsesClubUnioSchema(): void
    {
        // Hosted transaction pools must not rely on session search_path.
        if (! str_contains($this->getTable(), '.')) {
            $this->setTable('clubora.'.$this->getTable());
        }
    }
}
