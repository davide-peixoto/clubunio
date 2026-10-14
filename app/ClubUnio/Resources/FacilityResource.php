<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\FacilityService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

abstract class FacilityResource extends ContributionTrainingResource
{
    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->label('Hinzufügen')->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(FacilityService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), null, $data)));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()->iconButton()->tooltip('Bearbeiten')->using(fn (Model $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(FacilityService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), $record->id, $data)));
    }
}
