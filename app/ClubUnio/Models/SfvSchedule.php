<?php

namespace App\ClubUnio\Models;

class SfvSchedule extends CatalogModel
{
    protected $table = 'sfv_schedule';

    protected $casts = ['starts_at' => 'immutable_datetime'];
}
