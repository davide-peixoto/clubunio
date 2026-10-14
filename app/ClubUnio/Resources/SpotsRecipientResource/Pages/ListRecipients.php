<?php

namespace App\ClubUnio\Resources\SpotsRecipientResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\SpotsRecipientResource;
use Filament\Resources\Pages\ListRecords;

class ListRecipients extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = SpotsRecipientResource::class;
}
