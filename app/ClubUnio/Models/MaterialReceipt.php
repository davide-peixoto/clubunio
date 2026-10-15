<?php

namespace App\ClubUnio\Models;

class MaterialReceipt extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['items' => 'array', 'snapshot' => 'array', 'revision' => 'integer',
        'handover_on' => 'date', 'issued_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
