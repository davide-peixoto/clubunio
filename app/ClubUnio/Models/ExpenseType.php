<?php

namespace App\ClubUnio\Models;

class ExpenseType extends CatalogModel
{
    protected $casts = ['active' => 'boolean', 'receipt_required' => 'boolean'];
}
