<?php

namespace App\ClubUnio\Models;

class FamilyMember extends CatalogModel
{
    public function family()
    {
        return $this->belongsTo(Family::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
