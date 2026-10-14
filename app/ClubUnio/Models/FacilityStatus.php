<?php

namespace App\ClubUnio\Models;

class FacilityStatus extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'blocks_training' => 'boolean'];
}
