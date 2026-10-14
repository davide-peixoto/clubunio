<?php

namespace App\ClubUnio\Models;

class Expense extends CatalogModel
{
    public function bexioBalance()
    {
        return $this->hasOne(BexioBalance::class, 'object_id')->where('object_type', 'expense');
    }
    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    protected $casts = ['active' => 'boolean', 'released_on' => 'date', 'payment_released_on' => 'date', 'paid_on' => 'date'];

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function teamSeason()
    {
        return $this->belongsTo(TeamSeason::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(MemberBankAccount::class, 'bank_account_id');
    }

    public function status()
    {
        return $this->belongsTo(ExpenseStatus::class, 'status_id');
    }

    public function round()
    {
        return $this->belongsTo(ExpenseRound::class, 'round_id');
    }

    public function lines()
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function activeLines()
    {
        return $this->lines()->where('active', true);
    }
}
