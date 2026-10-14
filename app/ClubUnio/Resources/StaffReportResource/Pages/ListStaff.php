<?php

namespace App\ClubUnio\Resources\StaffReportResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\StaffReportResource;
use Filament\Resources\Pages\ListRecords;

class ListStaff extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = StaffReportResource::class;
}
