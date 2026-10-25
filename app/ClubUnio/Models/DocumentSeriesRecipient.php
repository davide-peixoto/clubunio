<?php

namespace App\ClubUnio\Models;

class DocumentSeriesRecipient extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['snapshot' => 'encrypted:array', 'lease_until' => 'datetime', 'checked_at' => 'datetime'];

    public function receipt()
    {
        return $this->belongsTo(MaterialReceipt::class, 'receipt_id');
    }
}
