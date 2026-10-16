<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\FamilyService;
use App\ClubUnio\Services\TeamAssignmentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

trait AssignmentRowActions
{
    private static function rowValues(Model $record, $livewire): array
    {
        $fields = static::form(Schema::make($livewire))->getComponents(withHidden: true);
        $values = $record->only(array_map(fn ($field) => $field->getName(), $fields));
        if (is_subclass_of(static::class, TeamAssignmentResource::class)) {
            $values['_version'] = FamilyService::version($record);
        }

        return $values;
    }

    private static function saveRow($livewire, ?Model $record, array $data): Model
    {
        return FormMutation::run($livewire, function () use ($record, $data) {
            $actor = Filament::auth()->user()?->fresh();
            abort_unless($actor && ProfileUi::model(static::getModel(), $record ? 'deactivate' : 'copy'), 403);
            if ($record) {
                static::getEloquentQuery()->findOrFail($record->id);
            }
            $service = is_subclass_of(static::class, TeamAssignmentResource::class)
                ? TeamAssignmentService::class : ContributionTrainingService::class;

            return app($service)->save($actor, Filament::getTenant(), static::getModel(), $record?->id, $data);
        });
    }

    public static function copyAction(): Action
    {
        return Action::make('copyRow')->label('Kopieren')->icon('heroicon-o-document-duplicate')->iconButton()->tooltip('Kopieren')
            ->schema(function ($livewire) {
                $fields = static::form(Schema::make($livewire))->getComponents(withHidden: true);
                foreach ($fields as $field) {
                    $field->disabled(false);
                }

                return $fields;
            })->fillForm(function (Model $record, $livewire) {
                $data = static::rowValues($record, $livewire);
                unset($data['revision'], $data['_version']);
                $data['active'] = true;

                return $data;
            })->action(fn (array $data, $livewire) => static::saveRow($livewire, null, $data));
    }

    public static function deactivateAction(): Action
    {
        return Action::make('deactivateRow')->label('Deaktivieren')->icon('heroicon-o-archive-box')->color('danger')->iconButton()->tooltip('Deaktivieren')
            ->visible(fn (Model $record) => $record->active)->requiresConfirmation()
            ->modalDescription('Der Eintrag wird deaktiviert. Bestehende Verknüpfungen und die Historie bleiben erhalten.')
            ->schema([Hidden::make('_version'), Hidden::make('revision')])
            ->fillForm(fn (Model $record, $livewire) => static::rowValues($record, $livewire))
            ->action(function (Model $record, array $data, $livewire) {
                $values = static::rowValues($record, $livewire);
                $version = array_key_exists('revision', $values) ? 'revision' : '_version';
                $values[$version] = $data[$version] ?? null;

                return static::saveRow($livewire, $record, ['active' => false] + $values);
            });
    }
}
