<?php

namespace App\ClubUnio\Models;

class ExpenseLine extends CatalogModel
{
    protected $hidden = ['storage_reference'];

    protected $casts = ['active' => 'boolean', 'amount' => 'decimal:4', 'suggested_amount' => 'decimal:4', 'suggestion_accepted' => 'boolean'];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function expenseType()
    {
        return $this->belongsTo(ExpenseType::class);
    }

    public function gameType()
    {
        return $this->belongsTo(GameType::class);
    }
}
