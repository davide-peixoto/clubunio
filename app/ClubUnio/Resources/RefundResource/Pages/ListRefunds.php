<?php

namespace App\ClubUnio\Resources\RefundResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\RefundResource;
use Filament\Resources\Pages\ListRecords;

class ListRefunds extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = RefundResource::class;
}
