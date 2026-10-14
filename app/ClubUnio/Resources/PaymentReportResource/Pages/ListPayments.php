<?php

namespace App\ClubUnio\Resources\PaymentReportResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\PaymentReportResource;
use Filament\Resources\Pages\ListRecords;

class ListPayments extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = PaymentReportResource::class;
}
