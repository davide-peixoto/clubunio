<?php

namespace App\ClubUnio\Models;

class ClubEvent extends CatalogModel
{
    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function guests()
    {
        return $this->hasMany(EventGuest::class);
    }
}
