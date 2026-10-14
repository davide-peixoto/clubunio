<?php

namespace App\ClubUnio\Models;

class LegacyTrainingPlan extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['input_json' => 'array', 'plan_json' => 'array', 'imported_at' => 'datetime'];

    public function owner()
    {
        return $this->belongsTo(Account::class);
    }

    public function teamSeason()
    {
        return $this->belongsTo(TeamSeason::class);
    }
}
