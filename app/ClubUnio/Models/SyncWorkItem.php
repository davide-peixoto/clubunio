<?php

namespace App\ClubUnio\Models;

class SyncWorkItem extends CatalogModel
{
    protected $casts = ['updated_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime'];
}
