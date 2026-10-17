<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\DossierMaintenanceFields;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\DossierMaintenanceService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

abstract class EditableDossierRelationManager extends DossierRelationManager
{
    #[Locked]
    public array $itemBulkSnapshot = [];

    private function bulkItemActions($save, $canEdit): array
    {
        if (! in_array(static::$kind, ['functions', 'sponsorships', 'eventGuests'], true)) {
            return [];
        }

        return array_map(fn (bool $active) => BulkAction::make($active ? 'restoreItems' : 'deactivateItems')
            ->label($active ? 'Auswahl wiederherstellen' : 'Auswahl deaktivieren')
            ->icon($active ? 'heroicon-o-arrow-path' : 'heroicon-o-archive-box')->visible($canEdit)->requiresConfirmation()
            ->modalDescription('Maximal 100 Einträge. Bei einem Konflikt bleibt die gesamte Auswahl unverändert.')
            ->schema([Hidden::make('selection_count')])
            ->fillForm(function ($records) {
                $this->itemBulkSnapshot = [];
                foreach ($records as $record) {
                    $this->itemBulkSnapshot[$record->id] = hash('sha256', json_encode($this->itemValues($record), JSON_THROW_ON_ERROR));
                }

                return ['selection_count' => count($this->itemBulkSnapshot)];
            })
            ->action(function ($records) use ($save, $active) {
                FormMutation::run($this, function () use ($records, $save, $active) {
                    abort_unless($records->count() > 0 && $records->count() <= 100 && $records->count() === count($this->itemBulkSnapshot), 422);
                    DB::connection('clubunio')->transaction(function () use ($records, $save, $active) {
                        Tenant::whereKey(Filament::getTenant()->id)->lockForUpdate()->firstOrFail();
                        $locked = $this->getRelationship()->where('tenant_id', Filament::getTenant()->id)
                            ->whereIn('id', $records->pluck('id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                        abort_unless($locked->count() === $records->count(), 404);
                        foreach ($records as $selected) {
                            $record = $locked->get($selected->id);
                            $data = $this->itemValues($record);
                            if (! hash_equals($this->itemBulkSnapshot[$record->id] ?? '', hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)))) {
                                throw ValidationException::withMessages(['active' => 'Auswahl wurde inzwischen geändert. Bitte neu auswählen.']);
                            }
                            $save($record->id, array_replace($data, ['active' => $active]));
                        }
                    });
                    $this->itemBulkSnapshot = [];
                });
            })->deselectRecordsAfterCompletion(), [false, true]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function itemValues($record): array
    {
        $fields = match (static::$kind) {
            'functions' => ['club_function_id'],
            'sponsorships' => ['name', 'amount', 'notes'],
            'eventGuests' => ['club_event_id'],
            'keys' => ['number', 'name', 'color'],
        };
        $data = $record->only(['revision', 'active', ...$fields]);
        if (static::$kind === 'sponsorships') {
            $data['expires_on'] = substr((string) $record->getRawOriginal('expires_on'), 0, 10);
        }
        if (static::$kind === 'functions') {
            $data['qualification_ids'] = $record->qualifications()->pluck('clubora.qualifications.id')->all();
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        $canEdit = fn () => $this->getOwnerRecord() instanceof Member && $this->getOwnerRecord()->archived_at === null;
        $save = fn ($id, $data, $copyFrom = null) => app(DossierMaintenanceService::class)->save(Filament::auth()->user(), Filament::getTenant(), DossierMaintenanceService::MEMBER_MODELS[static::$kind], $this->getOwnerRecord()->id, $id, $data, $copyFrom);

        return parent::table($table)->toolbarActions($this->bulkItemActions($save, $canEdit))->headerActions([Action::make('addItem')->label('Hinzufügen')->icon('heroicon-o-plus')->visible($canEdit)->slideOver()->schema(fn () => DossierMaintenanceFields::make(static::$kind))->action(fn (array $data) => FormMutation::run($this, fn () => $save(null, $data)))])
            ->recordActions([Action::make('editItem')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Bearbeiten')->visible($canEdit)->slideOver()
                ->fillForm(fn ($record) => $this->itemValues($record))
                ->schema(fn () => DossierMaintenanceFields::make(static::$kind))->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save($record->id, $data))),
                ...(in_array(static::$kind, ['functions', 'sponsorships', 'eventGuests'], true) ? [
                    Action::make('copyItem')->label('Kopieren')->icon('heroicon-o-document-duplicate')->iconButton()->tooltip('Kopieren')->visible($canEdit)->slideOver()
                        ->fillForm(function ($record) {
                            $data = $this->itemValues($record);
                            $data['revision'] = 0;
                            $data['active'] = true;
                            // Require a deliberate new assignment or sponsorship term.
                            $data[match (static::$kind) {
                                'functions' => 'club_function_id',
                                'eventGuests' => 'club_event_id',
                                'sponsorships' => 'expires_on',
                            }] = null;

                            return $data;
                        })->schema(fn () => DossierMaintenanceFields::make(static::$kind))
                        ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save(null, array_replace($data, ['revision' => 0, 'active' => true]), $record->id))),
                    ...array_map(fn (bool $active) => Action::make($active ? 'restoreItem' : 'deactivateItem')
                        ->label($active ? 'Wiederherstellen' : 'Deaktivieren')->icon($active ? 'heroicon-o-arrow-path' : 'heroicon-o-archive-box')
                        ->iconButton()->tooltip($active ? 'Wiederherstellen' : 'Deaktivieren')->color($active ? 'primary' : 'danger')
                        ->visible(fn ($record) => $canEdit() && (bool) $record->active !== $active)->requiresConfirmation()
                        ->modalDescription($active ? 'Die Zuordnung wird erneut aktiviert. Bestehende aktive Zuordnungen werden geprüft.' : 'Der Eintrag bleibt mit seiner Historie erhalten.')
                        ->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')->required()])
                        ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save($record->id,
                            array_replace($this->itemValues($record), ['revision' => $data['revision'], 'active' => $active])))), [false, true]),
                ] : []),
                ...(static::$kind === 'keys' ? ActiveRecordActions::make(
                    fn ($record, $data, $active) => FormMutation::run($this, fn () => $save($record->id, array_replace($this->itemValues($record), ['revision' => $data['revision'], 'active' => $active]))),
                    fn ($record) => $canEdit(), deactivateLabel: 'Rückgabe erfassen'
                ) : []),
            ]);
    }
}
