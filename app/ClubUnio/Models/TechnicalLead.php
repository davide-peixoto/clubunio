<?php

namespace App\ClubUnio\Models;

class TechnicalLead extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function teamSeason()
    {
        return $this->belongsTo(TeamSeason::class);
    }
}
