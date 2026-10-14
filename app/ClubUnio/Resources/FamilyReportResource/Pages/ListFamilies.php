<?php

namespace App\ClubUnio\Resources\FamilyReportResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\FamilyReportResource;
use Filament\Resources\Pages\ListRecords;

class ListFamilies extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = FamilyReportResource::class;
}
