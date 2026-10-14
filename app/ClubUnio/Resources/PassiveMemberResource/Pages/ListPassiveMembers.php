<?php

namespace App\ClubUnio\Resources\PassiveMemberResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\PassiveMemberResource;
use Filament\Resources\Pages\ListRecords;

class ListPassiveMembers extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = PassiveMemberResource::class;
}
