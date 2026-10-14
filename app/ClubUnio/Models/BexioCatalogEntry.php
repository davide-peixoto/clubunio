<?php

namespace App\ClubUnio\Models;

class BexioCatalogEntry extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'provider_active' => 'boolean', 'provider_data' => 'array',
        'valid_from' => 'date', 'valid_until' => 'date', 'observed_at' => 'datetime'];
}
