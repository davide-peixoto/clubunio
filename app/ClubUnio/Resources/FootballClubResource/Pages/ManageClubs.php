<?php

namespace App\ClubUnio\Resources\FootballClubResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\FootballClubResource;
use Filament\Resources\Pages\ManageRecords;

class ManageClubs extends ManageRecords
{
    use HasSavedViews;

    protected static string $resource = FootballClubResource::class;
}
