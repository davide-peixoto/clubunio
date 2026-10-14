<?php

namespace App\ClubUnio\Models;

class Refund extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['entitlement_snapshot' => 'array', 'confirmed_at' => 'datetime', 'paid_on' => 'date'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function status()
    {
        return $this->belongsTo(FinancialAdjustmentStatus::class);
    }
}
