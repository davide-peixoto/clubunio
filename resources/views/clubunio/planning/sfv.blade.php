@extends('clubunio.planning.layout')
@section('content')
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>SFV-Spiele</h1>
<form method="get" class="planning-form">
<label>Von<input name="from" type="date" value="{{ $filters['from'] }}" required></label>
<label>Bis<input name="to" type="date" value="{{ $filters['to'] }}" required></label>
<div class="actions"><button type="submit">@svg('heroicon-o-funnel') Filtern</button></div>
</form>
@if($profileAllows('sync') || \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'competitions', 'sync'))<form method="post" action="{{ route('clubunio.planning.sfv.refresh',$tenant->slug) }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="from" value="{{ $filters['from'] }}"><input type="hidden" name="to" value="{{ $filters['to'] }}">
<button type="submit" class="primary">@svg('heroicon-o-arrow-path') Vom SFV aktualisieren</button></form>@endif
<div class="table-scroll"><table><thead><tr><th>Datum</th><th>Spiel</th><th>Ort</th><th>Heim/Auswärts</th><th>Verbandstatus</th><th>Übernahme</th></tr></thead><tbody>
@forelse($matches as $match)<tr><td>{{ \Carbon\Carbon::parse($match->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $match->title }}</td><td>{{ $match->location }}</td><td>{{ $match->home?'Heim':'Auswärts' }}</td><td>{{ $match->state }}</td><td>
@if($profileAllows('sync') && $profileAllows(isset($imported[$match->match_id]) ? 'update' : 'create'))<a href="{{ route('clubunio.planning.sfv.form',[$tenant->slug,$match->id]) }}">{{ isset($imported[$match->match_id])?'Änderungen prüfen':'Übernehmen' }}</a>@endif
@foreach($imported[$match->match_id]??[] as $event)<a href="{{ route('clubunio.planning.show',[$tenant->slug,$event->id]) }}">{{ $teams->firstWhere('id',$event->team_season_id)?->label }}</a>@endforeach
</td></tr>@empty<tr><td colspan="6">Keine Spiele im gewählten Zeitraum.</td></tr>@endforelse
</tbody></table></div>{{ $matches->links('clubunio.planning.pagination') }}
@endsection
