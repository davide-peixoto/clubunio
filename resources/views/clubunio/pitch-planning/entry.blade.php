@php
    $field = $fields->get($entry['field_id']);
    $area = collect($field['areas'] ?? [])->firstWhere('id', $entry['area_id']);
    $status = array_key_exists($entry['status'], $statuses) ? $entry['status'] : 'pending';
@endphp
<button type="button" wire:key="pitch-entry-{{ $entry['key'] }}-{{ $timeline ? 'timeline' : $day['date']->toDateString() }}"
    class="pitch-entry {{ $timeline ? 'pitch-timeline-entry' : '' }} pitch-entry-{{ $status }}"
    @if($timeline) style="top: {{ $entry['minute'] * 1.5 }}px; height: {{ min(2160 - $entry['minute'] * 1.5, max(88, ($entry['until'] - $entry['minute']) * 1.5 - 3)) }}px; left: calc({{ 100 * $entry['lane'] / $lanes }}% + 3px); width: calc({{ 100 / $lanes }}% - 6px)" @endif
    wire:click="editEntry(@js($entry['key']))" wire:loading.attr="disabled"
    aria-label="{{ __('Belegung öffnen: :title', ['title' => $entry['title']]) }}"
    draggable="{{ $entry['editable'] && $this->capability('manage') && $timeline ? 'true' : 'false' }}"
    x-on:dragstart="if (busy || $el.draggable === false) { $event.preventDefault(); return; } dragKey = @js($entry['key']); $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', dragKey)">
    <span class="pitch-entry-time">{{ $entry['time_label'] }}</span>
    <strong>{{ $entry['title'] }}</strong>
    <span>@if(!$timeline){{ $field['name'] ?? '' }} / @endif{{ $area['name'] ?? __('Ganzer Platz') }}</span>
    <span class="pitch-entry-meta">{{ $sources[$entry['source']] ?? '' }} <span class="pitch-status pitch-status-{{ $status }}">{{ $statuses[$status] }}</span>@if($entry['conflicts']) <span class="pitch-conflict">{{ __('Konflikt') }} ({{ count($entry['conflicts']) }})</span>@endif</span>
</button>
