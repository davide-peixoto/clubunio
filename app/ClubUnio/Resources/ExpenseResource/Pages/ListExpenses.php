<?php

namespace App\ClubUnio\Resources\ExpenseResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\Concerns\HasStatusTabs;
use App\ClubUnio\Resources\ExpenseResource;
use Filament\Resources\Pages\ListRecords;

class ListExpenses extends ListRecords
{
    use HasSavedViews;
    use HasStatusTabs;

    protected static string $resource = ExpenseResource::class;
}
