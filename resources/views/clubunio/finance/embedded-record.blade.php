<div style="display: grid; gap: 1.5rem">
    <x-filament::actions :actions="$this->getCachedHeaderActions()" />
    {{ $this->content }}
    <x-filament-actions::modals />
</div>
