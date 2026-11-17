@php($limit = config('clubunio-licenses')[$tenant->license_plan ?? ''] ?? null)
{{ $count }}
@if($limit !== null)
    / {{ $limit }}
    @if($count > $limit)
        <strong>{{ trans('platform_metrics.over_limit') }}</strong>
    @endif
@endif
