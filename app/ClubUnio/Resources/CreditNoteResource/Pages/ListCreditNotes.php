<?php

namespace App\ClubUnio\Resources\CreditNoteResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\CreditNoteResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotes extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = CreditNoteResource::class;
}
