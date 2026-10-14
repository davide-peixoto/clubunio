<?php

namespace App\ClubUnio\Resources\TeamSeasonResource\Pages;

use App\ClubUnio\Resources\TeamSeasonResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTeamSeason extends ViewRecord
{
    protected static string $resource = TeamSeasonResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->label;
    }
}
