<?php

namespace App\ClubUnio\Models;

class Competition extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'show_on_signage' => 'boolean', 'plays_on' => 'date'];

    public function field()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function hostRoom()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function guestRoom()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function hostSide()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function guestSide()
    {
        return $this->belongsTo(TrainingOption::class);
    }
}
