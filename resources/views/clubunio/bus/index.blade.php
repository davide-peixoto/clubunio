@extends('clubunio.bus.layout')
@section('content')
<div class="page-heading"><h1>Busreservierungen</h1>@if($teams->isNotEmpty())<a class="primary" href="{{ route('clubunio.bus.create',$tenant->slug) }}">@svg('heroicon-o-plus') Reservation erfassen</a>@endif</div>
<form method="get" class="toolbar">
<label>Bus<select name="bus"><option value="">Alle Busse</option>@foreach($buses as $bus)<option value="{{ $bus->id }}" @selected(($filters['bus']??'')==$bus->id)>{{ $bus->name }}</option>@endforeach</select></label>
<label>Mannschaft<select name="team"><option value="">Alle Mannschaften</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team']??'')==$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>Suche<input type="search" name="q" value="{{ $filters['q']??'' }}"></label>
<label>Von<input type="date" name="from" value="{{ $filters['from']??'' }}"></label><label>Bis<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<input type="hidden" name="status" value="{{ $filters['status']??'' }}"><button class="icon-button" title="Filtern" aria-label="Filtern">@svg('heroicon-o-funnel')</button><a href="{{ route('clubunio.bus.index',$tenant->slug) }}">Zurücksetzen</a>
</form>
<nav class="planning-tabs" aria-label="Reservierungsstatus"><a href="{{ request()->fullUrlWithQuery(['status'=>null,'page'=>null]) }}" @if(empty($filters['status'])) aria-current="page" @endif>Alle ({{ $counts->sum() }})</a>@foreach($statuses as $code=>$status)<a href="{{ request()->fullUrlWithQuery(['status'=>$code,'page'=>null]) }}" @if(($filters['status']??'')===$code) aria-current="page" @endif>{{ $status->name }} ({{ $counts[$code]??0 }})</a>@endforeach</nav>
<div class="table-scroll"><table><thead><tr><th>Von</th><th>Bis</th><th>Bus</th><th>Mannschaft</th><th>Ziel</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><a href="{{ route('clubunio.bus.show',[$tenant->slug,$row->id]) }}">{{ \Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</a></td><td>{{ \Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $buses->firstWhere('id',$row->bus_id)?->name }}</td><td>{{ $teams->firstWhere('id',$row->team_season_id)?->label??'Nicht zugeordnet' }}</td><td>{{ $row->location }}</td><td>@include('clubunio.bus.badge',['code'=>$row->status])</td></tr>@empty<tr><td colspan="6">Keine Reservationen gefunden.</td></tr>@endforelse
</tbody></table></div>{{ $rows->links('pagination::simple-default') }}
@endsection
