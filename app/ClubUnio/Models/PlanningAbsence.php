<?php

namespace App\ClubUnio\Models;

class PlanningAbsence extends CatalogModel
{
    protected $table = 'team_absences';

    protected $hidden = ['note_encrypted'];
}
