<?php

namespace App\ClubUnio\Resources\UnassignedMemberResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\UnassignedMemberResource;
use Filament\Resources\Pages\ListRecords;

class ListUnassignedMembers extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = UnassignedMemberResource::class;
}
