<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Expense;

class ExpensesByStatus extends StatusDistribution
{
    public static function canView(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('expenses');
    }

    public function getHeading(): string
    {
        return __('Spesenbelege nach Status');
    }

    protected function modelClass(): string
    {
        return Expense::class;
    }
}
