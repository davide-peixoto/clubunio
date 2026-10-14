<?php

namespace App\ClubUnio\Models;

class FieldAvailability extends CatalogModel
{
    public function field()
    {
        return $this->belongsTo(TrainingOption::class);
    }

    public function status()
    {
        return $this->belongsTo(FacilityStatus::class);
    }
}
