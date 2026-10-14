@extends('clubunio.planning.layout')
@section('content')
<div class="page-heading"><h1>Kalender</h1><div class="actions">
<a class="icon-button" title="Kalender exportieren" aria-label="Kalender exportieren" href="{{ route('clubunio.planning.calendar.export',[$tenant->slug]+$filters) }}">@svg('heroicon-o-arrow-down-tray')</a>
@if(count($managed))<a class="primary" href="{{ route('clubunio.planning.create',$tenant->slug) }}">@svg('heroicon-o-plus') Termin erstellen</a>@endif
</div></div>
<form method="get" class="toolbar calendar-filters">
<input type="hidden" name="view" value="{{ $filters['view'] }}">
<label>Datum<input name="date" type="date" value="{{ $filters['date'] }}" required></label>
<label>Saison<select name="season"><option value="">Alle Saisons</option>@foreach($seasons as $season)<option value="{{ $season->id }}" @selected(($filters['season']??'')==$season->id)>{{ $season->name }}</option>@endforeach</select></label>
<label>Mannschaft<select name="team"><option value="">Alle Mannschaften</option>@foreach($teams as $team)@if(empty($filters['season']) || $team->season_id==$filters['season'])<option value="{{ $team->id }}" @selected(($filters['team']??'')==$team->id)>{{ $team->label }}</option>@endif @endforeach</select></label>
@foreach(['kind'=>'Terminart','status'=>'Status'] as $field=>$label)<label>{{ $label }}<select name="{{ $field }}"><option value="">Alle</option>@foreach($options[$field]??[] as $option)<option value="{{ $option->code }}" @selected(($filters[$field]??'')===$option->code)>{{ $option->name }}</option>@endforeach</select></label>@endforeach
<label>Suche<input type="search" name="q" value="{{ $filters['q']??'' }}" maxlength="100"></label>
<label><input type="checkbox" name="buses" value="1" @checked(!empty($filters['buses']))> Busreservierungen</label>
<button class="icon-button" title="Filtern" aria-label="Filtern">@svg('heroicon-o-funnel')</button><a href="{{ route('clubunio.planning.calendar',$tenant->slug) }}">Zurücksetzen</a>
</form>
<div class="calendar-navigation"><div class="actions">
<a class="icon-button" title="Vorheriger Zeitraum" aria-label="Vorheriger Zeitraum" href="{{ request()->fullUrlWithQuery(array_replace($filters,['date'=>$previous->toDateString()])) }}">@svg('heroicon-o-chevron-left')</a>
<h2>{{ $filters['view']==='month' ? $anchor->locale('de_CH')->translatedFormat('F Y') : $start->format('d.m.Y').' – '.$end->subDay()->format('d.m.Y') }}</h2>
<a class="icon-button" title="Nächster Zeitraum" aria-label="Nächster Zeitraum" href="{{ request()->fullUrlWithQuery(array_replace($filters,['date'=>$next->toDateString()])) }}">@svg('heroicon-o-chevron-right')</a>
<a href="{{ request()->fullUrlWithQuery(array_replace($filters,['date'=>now('Europe/Zurich')->toDateString()])) }}">Heute</a></div>
<nav class="planning-tabs" aria-label="Kalenderansicht">@foreach(['month'=>'Monat','week'=>'Woche','teams'=>'Mannschaften'] as $key=>$label)<a href="{{ request()->fullUrlWithQuery(array_replace($filters,['view'=>$key])) }}" @if($filters['view']===$key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav></div>
<p>{{ $events->count() }} Termine · Europe/Zurich</p>
@if($events->isEmpty())<p class="notice">Keine Termine für diese Auswahl.</p>@endif
@if($filters['view']==='teams')
<div class="table-scroll"><table class="calendar-team-table"><thead><tr><th>Mannschaft</th>@foreach($days as $day)<th>{{ $day['date']->locale('de_CH')->translatedFormat('D d.m.') }}</th>@endforeach</tr></thead><tbody>
@foreach($teams->whereIn('id',$events->pluck('team_season_id')->unique()) as $team)<tr><th>{{ $team->label }}</th>@foreach($days as $day)<td>@foreach($day['events']->where('team_season_id',$team->id) as $event)@include('clubunio.planning.calendar-event')@endforeach</td>@endforeach</tr>@endforeach
</tbody></table></div>
@else
<div class="calendar-scroll"><div class="calendar-grid calendar-{{ $filters['view'] }}">
@foreach($days as $day)<section class="calendar-day {{ $day['date']->isToday()?'is-today':'' }} {{ $day['date']->month!==$anchor->month && $filters['view']==='month'?'outside-month':'' }}" aria-label="{{ $day['date']->format('d.m.Y') }}">
<h3><time datetime="{{ $day['date']->toDateString() }}">{{ $day['date']->locale('de_CH')->translatedFormat('D d.m.') }}</time></h3>
@foreach($day['events'] as $event)@include('clubunio.planning.calendar-event')@endforeach
@foreach($day['buses'] as $reservation)<article class="calendar-event">
<a href="{{ route('clubunio.bus.show',[$tenant->slug,$reservation->id]) }}">Bus: {{ $busNames[$reservation->bus_id]??'Reservation' }}</a>
<time>{{ \Carbon\CarbonImmutable::parse($reservation->starts_at)->tz('Europe/Zurich')->format('d.m. H:i') }} – {{ \Carbon\CarbonImmutable::parse($reservation->ends_at)->tz('Europe/Zurich')->format('d.m. H:i') }}</time>
<small>{{ $busTeams[$reservation->team_season_id]??'Ohne Mannschaftszuordnung' }}</small>
@include('clubunio.bus.badge',['code'=>$reservation->status,'label'=>$busStatuses[$reservation->status]??$reservation->status])
</article>@endforeach
</section>@endforeach
</div></div>
@endif
@if(!empty($filters['buses']))
<section><h2>Busreservierungen ({{ $busReservations->count() }})</h2>
<div class="table-scroll"><table><thead><tr><th>Bus</th><th>Von</th><th>Bis</th><th>Mannschaft</th><th>Ort</th><th>Status</th></tr></thead><tbody>
@forelse($busReservations as $reservation)<tr>
<td><a href="{{ route('clubunio.bus.show',[$tenant->slug,$reservation->id]) }}">{{ $busNames[$reservation->bus_id]??'Bus' }}</a></td>
<td>{{ \Carbon\CarbonImmutable::parse($reservation->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td>
<td>{{ \Carbon\CarbonImmutable::parse($reservation->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td>
<td>{{ $busTeams[$reservation->team_season_id]??'Ohne Mannschaftszuordnung' }}</td><td>{{ $reservation->location }}</td>
<td>@include('clubunio.bus.badge',['code'=>$reservation->status,'label'=>$busStatuses[$reservation->status]??$reservation->status])</td>
</tr>@empty<tr><td colspan="6">Keine Busreservierungen für diesen Zeitraum.</td></tr>@endforelse
</tbody></table></div></section>
@endif
@endsection
