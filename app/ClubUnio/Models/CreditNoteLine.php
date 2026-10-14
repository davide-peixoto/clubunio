<?php

namespace App\ClubUnio\Models;

class CreditNoteLine extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['tax_snapshot' => 'array'];

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class);
    }
}
