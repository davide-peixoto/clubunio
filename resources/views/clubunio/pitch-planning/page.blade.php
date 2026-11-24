<x-filament-panels::page>
    @if ($this->modernAvailable())
        <div class="cu-facility-switch" role="group" aria-label="{{ trans('facility_ui.design') }}">
            <span>{{ trans('facility_ui.design') }}</span>
            <x-filament::button wire:click="selectPlanningDesign('modern')" :color="$this->modernSelected() ? 'primary' : 'gray'" :aria-pressed="$this->modernSelected() ? 'true' : 'false'">{{ trans('facility_ui.modern') }}</x-filament::button>
            <x-filament::button wire:click="selectPlanningDesign('classic')" :color="! $this->modernSelected() ? 'primary' : 'gray'" :aria-pressed="! $this->modernSelected() ? 'true' : 'false'">{{ trans('facility_ui.classic') }}</x-filament::button>
        </div>
    @endif
    @include('clubunio.pitch-planning.planner')
</x-filament-panels::page>
