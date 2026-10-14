<?php

namespace App\ClubUnio\Models;

class MemberNote extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
