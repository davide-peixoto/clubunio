<?php

namespace App\ClubUnio\Resources\SeasonResource\Pages;

use App\ClubUnio\Resources\SeasonResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSeason extends ViewRecord
{
    protected static string $resource = SeasonResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }
}
