<?php

namespace App\ClubUnio\Widgets;

use App\ClubUnio\Models\Expense;

class ExpensesByStatus extends StatusDistribution
{
    protected ?string $heading = 'Spesenbelege nach Status';

    protected function modelClass(): string
    {
        return Expense::class;
    }
}
