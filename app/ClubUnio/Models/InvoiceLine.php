<?php

namespace App\ClubUnio\Models;

class InvoiceLine extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'gross_amount' => 'decimal:4', 'discount' => 'decimal:4', 'net_amount' => 'decimal:4', 'contribution' => 'boolean', 'tax_snapshot' => 'array'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function status()
    {
        return $this->belongsTo(InvoiceLineStatus::class, 'status_id');
    }
}
