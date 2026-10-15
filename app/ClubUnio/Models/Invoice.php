<?php

namespace App\ClubUnio\Models;

class Invoice extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'cash_rounding' => 'boolean'];

    public function creditNotes()
    {
        return $this->hasMany(CreditNote::class);
    }

    public function customerCreditAllocations()
    {
        return $this->hasMany(CustomerCreditAllocation::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    public function category()
    {
        return $this->belongsTo(InvoiceCategory::class, 'invoice_category_id');
    }

    public function bexioBalance()
    {
        return $this->hasOne(BexioBalance::class, 'object_id')->where('object_type', 'invoice');
    }

    public function workflowStatus()
    {
        return $this->belongsTo(InvoiceWorkflowStatus::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function status()
    {
        return $this->belongsTo(InvoiceStatus::class, 'status_id');
    }

    public function lines()
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function activeLines()
    {
        return $this->lines()->where('active', true);
    }
}
