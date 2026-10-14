<?php

namespace App\ClubUnio\Models;

class TeamStaff extends CatalogModel
{
    protected $table = 'team_staff';

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function teamSeason()
    {
        return $this->belongsTo(TeamSeason::class);
    }

    public function staffFunction()
    {
        return $this->belongsTo(StaffFunction::class);
    }
}
