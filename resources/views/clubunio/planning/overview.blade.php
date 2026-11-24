<div class="cu-team-stats">
@foreach(['events' => $events->count(), 'teams' => $events->pluck('team_season_id')->unique()->count(), 'conflicts' => $events->filter(fn ($item) => !empty($conflicts[$item->id]))->count()] as $key => $value)
<section><span>{{ trans('planning_ui.'.$key) }}</span><strong>{{ $value }}</strong><span class="cu-team-spark" aria-hidden="true">▂ ▅ ▃ ▆ ▄ █</span></section>
@endforeach
</div>
<section class="cu-team-agenda" aria-label="{{ trans('planning_ui.agenda') }}">
<h2>{{ trans('planning_ui.agenda') }}</h2>
@forelse($events->sortBy('starts_at')->groupBy(fn ($item) => \Carbon\CarbonImmutable::parse($item->starts_at)->tz('Europe/Zurich')->toDateString()) as $date => $items)
<div class="cu-team-agenda-day"><h3>{{ \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->translatedFormat('D d. F') }}</h3>
@foreach($items as $event)@include('clubunio.planning.calendar-event')@endforeach</div>
@empty<p>{{ trans('planning_ui.empty') }}</p>@endforelse
</section>
