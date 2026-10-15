<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\DossierMaintenanceFields;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\DossierMaintenanceService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Tables\Table;

abstract class EditableDossierRelationManager extends DossierRelationManager
{
    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        $canEdit = fn () => $this->getOwnerRecord() instanceof Member && $this->getOwnerRecord()->archived_at === null;
        $save = fn ($id, $data) => app(DossierMaintenanceService::class)->save(Filament::auth()->user(), Filament::getTenant(), DossierMaintenanceService::MEMBER_MODELS[static::$kind], $this->getOwnerRecord()->id, $id, $data);

        return parent::table($table)->headerActions([Action::make('addItem')->label('Hinzufügen')->icon('heroicon-o-plus')->visible($canEdit)->slideOver()->schema(fn () => DossierMaintenanceFields::make(static::$kind))->action(fn (array $data) => FormMutation::run($this, fn () => $save(null, $data)))])
            ->recordActions([Action::make('editItem')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Bearbeiten')->visible($canEdit)->slideOver()
                ->fillForm(function ($record) {
                    $data = $record->only(['revision', 'active', 'name', 'number', 'color', 'amount', 'notes', 'club_function_id', 'club_event_id']);
                    if (static::$kind === 'sponsorships') {
                        $data['expires_on'] = substr((string) $record->getRawOriginal('expires_on'), 0, 10);
                    }
                    if (static::$kind === 'functions') {
                        $data['qualification_ids'] = $record->qualifications()->pluck('clubora.qualifications.id')->all();
                    }

                    return $data;
                })->schema(fn () => DossierMaintenanceFields::make(static::$kind))->action(fn ($record, array $data) => FormMutation::run($this, fn () => $save($record->id, $data)))]);
    }
}
