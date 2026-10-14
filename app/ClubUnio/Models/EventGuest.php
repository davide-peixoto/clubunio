<?php

namespace App\ClubUnio\Models;

class EventGuest extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function clubEvent()
    {
        return $this->belongsTo(ClubEvent::class);
    }
}
