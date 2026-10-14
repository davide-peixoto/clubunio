<?php

namespace App\ClubUnio\Models;

class ContributionRule extends CatalogModel
{
    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function price()
    {
        return $this->belongsTo(ContributionPrice::class);
    }

    public function gender()
    {
        return $this->belongsTo(Gender::class);
    }
}
