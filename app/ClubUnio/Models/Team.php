<?php

namespace App\ClubUnio\Models;

class Team extends CatalogModel
{
    public function teamSeasons()
    {
        return $this->hasMany(TeamSeason::class);
    }
}
