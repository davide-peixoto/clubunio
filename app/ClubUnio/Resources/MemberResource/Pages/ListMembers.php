<?php

namespace App\ClubUnio\Resources\MemberResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\MemberResource;
use Filament\Resources\Pages\ListRecords;

class ListMembers extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = MemberResource::class;
}
