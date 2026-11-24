@php
    $payload = [
        'mode' => $mode, 'date' => $date, 'locale' => app()->getLocale(),
        'fields' => collect($options['fields'])->map(fn ($field) => \Illuminate\Support\Arr::only($field, ['id', 'name', 'available', 'areas']))->values()->all(),
        'days' => collect($days)->map(fn ($day) => [
            'date' => $day['date']->toDateString(),
            'label' => $day['date']->locale(app()->getLocale())->isoFormat('ddd, D. MMM'),
            'today' => $day['date']->isToday(),
            'events' => collect($day['events'])->map(fn ($entry) => \Illuminate\Support\Arr::only($entry, ['key', 'title', 'field_id', 'area_id', 'date', 'source', 'status', 'editable', 'minute', 'until', 'time_label']) + ['conflicts' => count($entry['conflicts'] ?? [])])->values()->all(),
        ])->values()->all(),
        'canRequest' => $this->capability('request'), 'canManage' => $this->capability('manage'),
        'text' => trans('facility_ui'), 'statuses' => $statuses, 'sources' => $sources,
    ];
@endphp
<div data-cu-facility-planner data-props="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}">
    <div wire:ignore data-cu-facility-canvas></div>
    <div data-cu-facility-fallback>@include('clubunio.pitch-planning.calendar')</div>
</div>
