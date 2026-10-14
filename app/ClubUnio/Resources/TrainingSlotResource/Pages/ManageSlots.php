<?php

namespace App\ClubUnio\Resources\TrainingSlotResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\TrainingSlotResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSlots extends ManageRecords
{
    use HasSavedViews;

    protected static string $resource = TrainingSlotResource::class;
}
