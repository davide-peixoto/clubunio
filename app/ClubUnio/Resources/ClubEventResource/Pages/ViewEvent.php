<?php

namespace App\ClubUnio\Resources\ClubEventResource\Pages;

use App\ClubUnio\Resources\ClubEventResource;
use App\ClubUnio\Resources\EventCheckinActions;
use Filament\Resources\Pages\ViewRecord;

class ViewEvent extends ViewRecord
{
    protected static string $resource = ClubEventResource::class;

    protected function getHeaderActions(): array
    {
        return EventCheckinActions::make(fn () => $this->getRecord());
    }
}
