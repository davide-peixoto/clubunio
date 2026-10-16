<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\DossierMaintenanceFields;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\DossierMaintenanceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Tables\Table;

abstract class EditableDossierRelationManager extends DossierRelationManager
{
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
        $save = fn ($id, $data) => app(DossierMaintenanceService::class)->save(Filament::auth()->user(), Filament::getTenant(), DossierMaintenanceService::MEMBER_MODELS[static::$kind], $this->getOwnerRecord()->id, $id, $data);

        return parent::table($table)->headerActions([Action::make('addItem')->label('Hinzufügen')->icon('heroicon-o-plus')->visible($canEdit)->slideOver()->schema(fn () => DossierMaintenanceFields::make(static::$kind))->action(fn (array $data) => FormMutation::run($this, fn () => $save(null, $data)))])
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
                        ->action(fn (array $data) => FormMutation::run($this, fn () => $save(null, array_replace($data, ['revision' => 0, 'active' => true])))),
                    ...array_map(fn (bool $active) => Action::make($active ? 'restoreItem' : 'deactivateItem')
                        ->label($active ? 'Wiederherstellen' : 'Deaktivieren')->icon($active ? 'heroicon-o-arrow-path' : 'heroicon-o-archive-box')
                        ->iconButton()->tooltip($active ? 'Wiederherstellen' : 'Deaktivieren')->color($active ? 'primary' : 'danger')
                        ->visible(fn ($record) => $canEdit() && (bool) $record->active !== $active)->requiresConfirmation()
                        ->modalDescription($active ? 'Die Zuordnung wird erneut aktiviert. Bestehende aktive Zuordnungen werden geprüft.' : 'Der Eintrag bleibt mit seiner Historie erhalten.')
                        ->fillForm(fn ($record) => ['revision' => $record->revision])->schema([Hidden::make('revision')->required()])
                        ->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save($record->id,
                            array_replace($this->itemValues($record), ['revision' => $data['revision'], 'active' => $active])))), [false, true]),
                ] : []),
            ]);
    }
}
