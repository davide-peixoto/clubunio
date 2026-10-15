<?php

namespace App\ClubUnio\Resources\CustomerCreditResource\Pages;

use App\ClubUnio\Resources\CustomerCreditResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerCredits extends ListRecords
{
    use \App\ClubUnio\Resources\Concerns\HasSavedViews;

    protected static string $resource = CustomerCreditResource::class;
}
