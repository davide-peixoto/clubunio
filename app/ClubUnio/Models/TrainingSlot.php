<?php

namespace App\ClubUnio\Models;

class TrainingSlot extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'all_fields' => 'boolean', 'without_main' => 'boolean', 'synthetic_only' => 'boolean', 'website_visible' => 'boolean'];

    public function teamSeason()
    {
        return $this->belongsTo(TeamSeason::class);
    }

    public function period()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function weekday()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function field()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function room()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function side()
    {
        return $this->belongsTo(TrainingOption::class);
    }
}
