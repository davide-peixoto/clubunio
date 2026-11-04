<?php

namespace App\ClubUnio\Resources\TrainingSlotResource\Pages;

use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\TrainingSlotResource;
use Filament\Resources\Pages\ManageRecords;

class ManageSlots extends ManageRecords
{
    use HasSavedViews;

    protected static string $resource = TrainingSlotResource::class;

    public function getSubheading(): ?string
    {
        $period = app(\App\ClubUnio\Services\ActiveTrainingPlan::class)->period(\Filament\Facades\Filament::getTenant());
        return __('Aktiver Trainingsplan: :period', ['period' => $period ? app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', $period) : __('Nicht festgelegt')]);
    }
}
