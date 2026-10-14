<?php

namespace App\ClubUnio\Resources\BankAccountReportResource\Pages;

use App\ClubUnio\Resources\BankAccountReportResource;
use App\ClubUnio\Resources\Concerns\HasSavedViews;
use Filament\Resources\Pages\ListRecords;

class ListBankAccounts extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = BankAccountReportResource::class;
}
