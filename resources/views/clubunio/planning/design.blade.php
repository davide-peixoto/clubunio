<nav class="cu-team-design" aria-label="{{ trans('planning_ui.design') }}">
@foreach(['modern', 'classic'] as $design)
<a href="{{ request()->fullUrlWithQuery(['planning_design' => $design]) }}" @if($teamModern === ($design === 'modern')) aria-current="true" @endif>{{ trans('planning_ui.'.$design) }}</a>
@endforeach
</nav>
@if($teamModern)
<header class="cu-team-hero">
    <div><small>{{ trans('planning_ui.eyebrow') }}</small><h2>{{ trans('planning_ui.title') }}</h2><p>{{ trans('planning_ui.description') }}</p></div>
    <a href="{{ route('clubunio.planning.'.(request()->routeIs('clubunio.workspace.planning.calendar') ? 'index' : 'calendar'), $tenant->slug) }}">{{ trans('planning_ui.'.(request()->routeIs('clubunio.workspace.planning.calendar') ? 'list' : 'calendar')) }} <span aria-hidden="true">↗</span></a>
    <svg class="cu-team-art" viewBox="0 0 240 160" fill="none" aria-hidden="true"><circle cx="140" cy="80" r="70" stroke="currentColor"/><circle cx="140" cy="80" r="48" stroke="currentColor"/><path d="M20 130L80 60L130 100L220 20" stroke="currentColor" stroke-width="12" stroke-linecap="round" stroke-linejoin="round"/></svg>
</header>
@endif
