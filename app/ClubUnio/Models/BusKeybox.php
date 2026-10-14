<?php

namespace App\ClubUnio\Models;

class BusKeybox extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public function bus()
    {
        return $this->belongsTo(Bus::class);
    }
}
