<?php

namespace App\ClubUnio\Resources\AnnouncementResource\Pages;

use App\ClubUnio\Resources\AnnouncementResource;
use Filament\Resources\Pages\ManageRecords;

class ListAnnouncements extends ManageRecords
{
    protected static string $resource = AnnouncementResource::class;
}
