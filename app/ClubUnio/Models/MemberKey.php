<?php

namespace App\ClubUnio\Models;

class MemberKey extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
