<?php

namespace App\ClubUnio\Models;

class CustomerCredit extends CatalogModel
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function allocations()
    {
        return $this->hasMany(CustomerCreditAllocation::class);
    }

    public function getAllocatedCentsAttribute(): int
    {
        return array_key_exists('allocations_sum_amount_cents', $this->attributes)
            ? (int) $this->attributes['allocations_sum_amount_cents'] : (int) $this->allocations()->sum('amount_cents');
    }

    public function getRemainingCentsAttribute(): int
    {
        return max(0, (int) $this->amount_cents - $this->allocated_cents);
    }
}
