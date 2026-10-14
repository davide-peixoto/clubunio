<?php

namespace App\ClubUnio\Models;

class Sponsorship extends CatalogModel
{
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    protected $casts = ['active' => 'boolean', 'amount' => 'decimal:4', 'expires_on' => 'date'];
}
