@extends('clubunio.planning.layout')
@section('content')
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<div class="page-heading"><h1>{{ __('Kalender') }}</h1><div class="actions">
@if($profileAllows('export') && (empty($filters['buses']) || \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'bus', 'export')))<a class="icon-button" title="{{ __('Kalender exportieren') }}" aria-label="{{ __('Kalender exportieren') }}" href="{{ route('clubunio.planning.calendar.export',[$tenant->slug]+$filters) }}">@svg('heroicon-o-arrow-down-tray')</a>@endif
@if(count($managed) && $profileAllows('create'))<a class="primary" href="{{ route('clubunio.planning.create',$tenant->slug) }}">@svg('heroicon-o-plus') {{ __('Termin erstellen') }}</a>@endif
</div></div>
<form method="get" class="toolbar calendar-filters">
<input type="hidden" name="view" value="{{ $filters['view'] }}">
<label>{{ __('Datum') }}<input name="date" type="date" value="{{ $filters['date'] }}" required></label>
<label>{{ __('Saison') }}<select name="season"><option value="">{{ __('Alle Saisons') }}</option>@foreach($seasons as $season)<option value="{{ $season->id }}" @selected(($filters['season']??'')==$season->id)>{{ $season->name }}</option>@endforeach</select></label>
<label>{{ __('Mannschaft') }}<select name="team"><option value="">{{ __('Alle Mannschaften') }}</option>@foreach($teams as $team)@if(empty($filters['season']) || $team->season_id==$filters['season'])<option value="{{ $team->id }}" @selected(($filters['team']??'')==$team->id)>{{ $team->label }}</option>@endif @endforeach</select></label>
@foreach(['kind'=>__('Terminart'),'status'=>__('Status')] as $field=>$label)<label>{{ $label }}<select name="{{ $field }}"><option value="">{{ __('Alle') }}</option>@foreach($options[$field]??[] as $option)<option value="{{ $option->code }}" @selected(($filters[$field]??'')===$option->code)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', $option, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>@endforeach
<label>{{ __('Suche') }}<input type="search" name="q" value="{{ $filters['q']??'' }}" maxlength="100"></label>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'bus', 'view'))<label><input type="checkbox" name="buses" value="1" @checked(!empty($filters['buses']))> {{ __('Busreservierungen') }}</label>@endif
<button class="icon-button" title="{{ __('Filtern') }}" aria-label="{{ __('Filtern') }}">@svg('heroicon-o-funnel')</button><a href="{{ route('clubunio.planning.calendar',$tenant->slug) }}">{{ __('Zurücksetzen') }}</a>
</form>
<div class="calendar-navigation"><div class="actions">
<a class="icon-button" title="{{ __('Vorheriger Zeitraum') }}" aria-label="{{ __('Vorheriger Zeitraum') }}" href="{{ request()->fullUrlWithQuery(array_replace($filters,['date'=>$previous->toDateString()])) }}">@svg('heroicon-o-chevron-left')</a>
<h2>{{ $filters['view']==='month' ? $anchor->locale(app()->getLocale())->translatedFormat('F Y') : $start->format('d.m.Y').' – '.$end->subDay()->format('d.m.Y') }}</h2>
<a class="icon-button" title="{{ __('Nächster Zeitraum') }}" aria-label="{{ __('Nächster Zeitraum') }}" href="{{ request()->fullUrlWithQuery(array_replace($filters,['date'=>$next->toDateString()])) }}">@svg('heroicon-o-chevron-right')</a>
<a href="{{ request()->fullUrlWithQuery(array_replace($filters,['date'=>now('Europe/Zurich')->toDateString()])) }}">{{ __('Heute') }}</a></div>
<nav class="planning-tabs" aria-label="{{ __('Kalenderansicht') }}">@foreach(['month'=>__('Monat'),'week'=>__('Woche'),'teams'=>__('Mannschaften')] as $key=>$label)<a href="{{ request()->fullUrlWithQuery(array_replace($filters,['view'=>$key])) }}" @if($filters['view']===$key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav></div>
<p>{{ $events->count() }} {{ __('Termine · Europe/Zurich') }}</p>
@if($events->isEmpty())<p class="notice">{{ __('Keine Termine für diese Auswahl.') }}</p>@endif
@if($filters['view']==='teams')
<div class="table-scroll"><table class="calendar-team-table"><thead><tr><th>{{ __('Mannschaft') }}</th>@foreach($days as $day)<th>{{ $day['date']->locale(app()->getLocale())->translatedFormat('D d.m.') }}</th>@endforeach</tr></thead><tbody>
@foreach($teams->whereIn('id',$events->pluck('team_season_id')->unique()) as $team)<tr><th>{{ $team->label }}</th>@foreach($days as $day)<td>@foreach($day['events']->where('team_season_id',$team->id) as $event)@include('clubunio.planning.calendar-event')@endforeach</td>@endforeach</tr>@endforeach
</tbody></table></div>
@else
<div class="calendar-scroll"><div class="calendar-grid calendar-{{ $filters['view'] }}">
@foreach($days as $day)<section class="calendar-day {{ $day['date']->isToday()?'is-today':'' }} {{ $day['date']->month!==$anchor->month && $filters['view']==='month'?'outside-month':'' }}" aria-label="{{ $day['date']->format('d.m.Y') }}">
<h3><time datetime="{{ $day['date']->toDateString() }}">{{ $day['date']->locale(app()->getLocale())->translatedFormat('D d.m.') }}</time></h3>
@foreach($day['events'] as $event)@include('clubunio.planning.calendar-event')@endforeach
@foreach($day['buses'] as $reservation)<article class="calendar-event">
<a href="{{ route('clubunio.bus.show',[$tenant->slug,$reservation->id]) }}">{{ __('Bus:') }} {{ $busNames[$reservation->bus_id]??__('Reservation') }}</a>
<time>{{ \Carbon\CarbonImmutable::parse($reservation->starts_at)->tz('Europe/Zurich')->format('d.m. H:i') }} – {{ \Carbon\CarbonImmutable::parse($reservation->ends_at)->tz('Europe/Zurich')->format('d.m. H:i') }}</time>
<small>{{ $busTeams[$reservation->team_season_id]??__('Ohne Mannschaftszuordnung') }}</small>
@include('clubunio.bus.badge',['code'=>$reservation->status,'label'=>$busStatuses[$reservation->status]??$reservation->status])
</article>@endforeach
</section>@endforeach
</div></div>
@endif
@if(!empty($filters['buses']))
<section><h2>{{ __('Busreservierungen (') }}{{ $busReservations->count() }})</h2>
<div class="table-scroll"><table><thead><tr><th>{{ __('Bus') }}</th><th>{{ __('Von') }}</th><th>{{ __('Bis') }}</th><th>{{ __('Mannschaft') }}</th><th>{{ __('Ort') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
@forelse($busReservations as $reservation)<tr>
<td><a href="{{ route('clubunio.bus.show',[$tenant->slug,$reservation->id]) }}">{{ $busNames[$reservation->bus_id]??__('Bus') }}</a></td>
<td>{{ \Carbon\CarbonImmutable::parse($reservation->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td>
<td>{{ \Carbon\CarbonImmutable::parse($reservation->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td>
<td>{{ $busTeams[$reservation->team_season_id]??__('Ohne Mannschaftszuordnung') }}</td><td>{{ $reservation->location }}</td>
<td>@include('clubunio.bus.badge',['code'=>$reservation->status,'label'=>$busStatuses[$reservation->status]??$reservation->status])</td>
</tr>@empty<tr><td colspan="6">{{ __('Keine Busreservierungen für diesen Zeitraum.') }}</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
