<?php

namespace App\ClubUnio\Models;

class LegacyMaterialReceipt extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['signed_at' => 'datetime'];

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
