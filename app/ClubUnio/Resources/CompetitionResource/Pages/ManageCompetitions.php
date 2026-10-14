<?php

namespace App\ClubUnio\Resources\CompetitionResource\Pages;

use App\ClubUnio\Resources\CompetitionResource;
use App\ClubUnio\Resources\Concerns\HasSavedViews;
use Filament\Resources\Pages\ManageRecords;

class ManageCompetitions extends ManageRecords
{
    use HasSavedViews;

    protected static string $resource = CompetitionResource::class;

    protected function savedFilterRules(): array
    {
        return ['upcoming' => ['isActive' => 'required|boolean'], 'missing_club' => ['isActive' => 'required|boolean'], 'date' => ['from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d']];
    }
}
