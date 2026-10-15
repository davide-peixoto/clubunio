<?php

namespace App\ClubUnio\Resources\MemberChangeRequestResource\Pages;

use App\ClubUnio\Resources\MemberChangeRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ListRequests extends ManageRecords
{
    protected static string $resource = MemberChangeRequestResource::class;
}
