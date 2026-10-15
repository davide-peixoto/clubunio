<?php

namespace App\ClubUnio\Models;

class CreditNote extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['source_snapshot' => 'array', 'external_snapshot' => 'array', 'released_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function status()
    {
        return $this->belongsTo(FinancialAdjustmentStatus::class);
    }

    public function lines()
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function customerCredit()
    {
        return $this->hasOne(CustomerCredit::class);
    }
}
