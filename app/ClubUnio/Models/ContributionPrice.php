<?php

namespace App\ClubUnio\Models;

class ContributionPrice extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'amount' => 'decimal:4'];
}
