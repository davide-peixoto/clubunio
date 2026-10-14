<?php

namespace App\ClubUnio\Resources\OpenInvoiceResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\OpenInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListOpenInvoices extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = OpenInvoiceResource::class;
}
