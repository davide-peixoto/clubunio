<?php

namespace App\ClubUnio\Resources\TeamResource\Pages;

use App\ClubUnio\Resources\TeamResource;
use Filament\Resources\Pages\ViewRecord;

class ViewTeam extends ViewRecord
{
    protected static string $resource = TeamResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }
}
