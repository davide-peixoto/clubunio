@extends('clubunio.planning.layout')
@section('content')
<div class="page-heading"><h1>Teamplanung</h1>@if(count($managed))<a class="primary" href="{{ route('clubunio.planning.create',$tenant->slug) }}">@svg('heroicon-o-plus') Termin erstellen</a>@endif</div>
<form method="get" class="toolbar">
<label>Mannschaft<select name="team"><option value="">Alle Mannschaften</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team']??'')==$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>Suche<input name="q" value="{{ $filters['q']??'' }}" type="search"></label>
<label>Von<input name="from" type="date" value="{{ $filters['from']??'' }}"></label><label>Bis<input name="to" type="date" value="{{ $filters['to']??'' }}"></label>
<input type="hidden" name="status" value="{{ $filters['status']??'' }}"><button class="icon-button" title="Filtern" aria-label="Filtern">@svg('heroicon-o-funnel')</button><a href="{{ route('clubunio.planning.index',$tenant->slug) }}">Zurücksetzen</a>
</form>
<nav class="planning-tabs" aria-label="Terminstatus"><a href="{{ request()->fullUrlWithQuery(['status'=>null,'page'=>null]) }}" @if(empty($filters['status'])) aria-current="page" @endif>Alle ({{ $counts->sum() }})</a>
@foreach($options['status']??[] as $code=>$option)<a href="{{ request()->fullUrlWithQuery(['status'=>$code,'page'=>null]) }}" @if(($filters['status']??'')===$code) aria-current="page" @endif>{{ $option->name }} ({{ $counts[$code]??0 }})</a>@endforeach</nav>
<div class="table-scroll"><table><thead><tr><th>Beginn</th><th>Termin</th><th>Mannschaft</th><th>Ort</th><th>Status</th><th>Frist</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ \Carbon\Carbon::parse($event->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td><a href="{{ route('clubunio.planning.show',[$tenant->slug,$event->id]) }}">{{ $event->title }}</a><small>{{ $options['kind'][$event->kind]->name??$event->kind }}</small></td><td>{{ $teams->firstWhere('id',$event->team_season_id)?->label }}</td><td>{{ $event->location }}</td><td>@include('clubunio.planning.badge',['category'=>'status','code'=>$event->status])</td><td>{{ $event->deadline_at ? \Carbon\Carbon::parse($event->deadline_at)->tz('Europe/Zurich')->format('d.m.Y H:i') : 'Keine Frist' }}</td></tr>
@empty<tr><td colspan="6">Keine Termine gefunden.</td></tr>@endforelse
</tbody></table></div>{{ $events->links('pagination::simple-default') }}
@endsection
