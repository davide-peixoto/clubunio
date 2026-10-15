<?php

namespace App\ClubUnio\Models;

class CustomerCreditAllocation extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $casts = ['evidence' => 'array'];

    public function customerCredit()
    {
        return $this->belongsTo(CustomerCredit::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
