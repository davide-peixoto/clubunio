<?php

namespace App\ClubUnio\Resources\FamilyResource\Pages;

use App\ClubUnio\Resources\FamilyResource;
use Filament\Resources\Pages\ViewRecord;

class ViewFamily extends ViewRecord
{
    protected static string $resource = FamilyResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->name;
    }
}
