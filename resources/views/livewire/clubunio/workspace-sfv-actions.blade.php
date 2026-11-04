@php($match = $getRecord())
<div class="flex flex-wrap items-center gap-3">
    @if($profileAllows('sync') && $profileAllows(isset($imported[$match->match_id]) ? 'update' : 'create'))
        <x-filament::link :href="route('clubunio.workspace.planning.sfv.form', $match->id)" icon="heroicon-o-arrow-down-tray">{{ isset($imported[$match->match_id]) ? __('Änderungen prüfen') : __('Übernehmen') }}</x-filament::link>
    @endif
    @foreach($imported[$match->match_id] ?? [] as $event)
        <x-filament::link :href="route('clubunio.workspace.planning.show', $event->id)">{{ $teams->firstWhere('id', $event->team_season_id)?->label }}</x-filament::link>
    @endforeach
</div>
