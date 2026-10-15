<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\ContributionTrainingService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

abstract class ContributionTrainingResource extends CatalogResource
{
    use AssignmentRowActions;

    public static function createAction(): CreateAction
    {
        return CreateAction::make()->label('Hinzufügen')->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(ContributionTrainingService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), null, $data)));
    }

    public static function editAction(): EditAction
    {
        return EditAction::make()->iconButton()->tooltip('Bearbeiten')->using(fn (Model $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(ContributionTrainingService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getModel(), $record->id, $data)));
    }

    public static function options(string $model, string $label = 'name'): array
    {
        return $model::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy($label)->pluck($label, 'id')->all();
    }
}
