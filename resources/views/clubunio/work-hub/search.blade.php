<x-filament-panels::page>
<div class="cu-workflow cu-work-hub">
<p class="cu-module-note">{{ trans('work_hub.search_hint') }}</p>
<form wire:submit="search" class="cu-hub-search"><label>{{ trans('work_hub.term') }}<x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass"><x-filament::input type="search" wire:model="q" minlength="2" maxlength="100" /></x-filament::input.wrapper></label><x-filament::button type="submit" wire:loading.attr="disabled">{{ trans('work_hub.search_submit') }}</x-filament::button></form>
<p role="status" wire:loading.delay>{{ trans('work_hub.loading') }}</p>
<div class="cu-workflow-cards" aria-live="polite">@forelse($results as $item)<article class="cu-workflow-section"><small>{{ $item['kind'] }}</small><h2><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></h2><p>{{ $item['detail'] }}</p></article>@empty<p>{{ trans('work_hub.no_results') }}</p>@endforelse</div>
</div>
</x-filament-panels::page>
