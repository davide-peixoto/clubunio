<?php

namespace App\ClubUnio\Resources;

use Filament\Facades\Filament;
use Livewire\Attributes\Locked;

/** Reuse the full financial workflow in a parent-bound dossier dialog. */
trait EmbeddedFinancialRecord
{
    #[Locked]
    public ?int $dossierId = null;

    public function mount(int|string $record, ?int $dossierId = null): void
    {
        $this->dossierId = $dossierId;
        parent::mount($record);
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();
        $tenant = Filament::getTenant();
        $actor = Filament::auth()->user()?->fresh();
        $parent = static::DOSSIER_MODEL::where('tenant_id', $tenant->id)->find($this->dossierId);
        $record = $this->getRecord()->fresh();
        abort_unless($actor?->canAccessTenant($tenant) && $parent && $actor->can('view', $parent)
            && $record && (int) $record->tenant_id === (int) $tenant->id
            && (int) $record->{static::DOSSIER_FIELD} === (int) $parent->id, 403);
        $this->record = $record;
    }

    public function redirect($url, $navigate = false)
    {
        // The normal pages redirect after writes; embedded workflows stay in their dossier.
        $this->record->refresh();
        $this->dispatch('dossier-financial-updated');
    }
}
