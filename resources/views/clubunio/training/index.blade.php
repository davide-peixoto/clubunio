@extends('clubunio.planning.layout')
@section('content')
<div class="page-heading"><h1>Trainingspläne</h1><a class="primary" href="{{ route('clubunio.training.create',$tenant->slug) }}">@svg('heroicon-o-plus') Neuer Trainingsplan</a></div>
<form class="toolbar" method="get"><label>Mannschaft<select name="team"><option value="">Alle Mannschaften</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(request('team') == $team->id)>{{ $team->label }}</option>@endforeach</select></label><label>Bestand<select name="archived"><option value="0">Aktuell</option><option value="1" @selected(request('archived') === '1')>Archiv</option></select></label><label>Suche<input name="q" value="{{ request('q') }}" maxlength="100"></label><button class="icon-button" title="Suchen" aria-label="Suchen">@svg('heroicon-o-magnifying-glass')</button></form>
<div class="table-scroll"><table><thead><tr><th>Training</th><th>Mannschaft</th><th>Freigabe</th><th>Geändert</th></tr></thead><tbody>
@forelse($plans as $row)<tr><td><a href="{{ route('clubunio.training.show',[$tenant->slug,$row->id]) }}">{{ $row->title }}</a></td><td>{{ $teams->firstWhere('id',$row->team_season_id)?->label }}</td><td>{{ $row->shared ? 'Teamverantwortliche' : 'Privat' }}</td><td>{{ \Carbon\CarbonImmutable::parse($row->updated_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</td></tr>@empty<tr><td colspan="4">Keine Trainingspläne vorhanden.</td></tr>@endforelse
</tbody></table></div>{{ $plans->links('clubunio.planning.pagination') }}
@endsection
