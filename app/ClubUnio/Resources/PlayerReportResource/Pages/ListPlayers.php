<?php

namespace App\ClubUnio\Resources\PlayerReportResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\PlayerReportResource;
use Filament\Resources\Pages\ListRecords;

class ListPlayers extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = PlayerReportResource::class;
}
