@extends('clubunio.portal.layout')
@section('content')
<h1>Mitgliederanmeldungen</h1>
<form method="get" class="filters">
    <label>Suche<input type="search" name="q" value="{{ $q }}" maxlength="120"></label>
    <label>Status<select name="status"><option value="">Alle</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status') === (string)$status->id)>{{ $status->name }}</option>@endforeach</select></label>
    <button type="submit" class="icon-button" title="Suchen" aria-label="Suchen">@svg('heroicon-o-magnifying-glass')</button>
</form>
<div class="table-scroll"><table><thead><tr><th>Name</th><th>Status</th><th>Mannschaft / Saison</th><th></th></tr></thead><tbody>
@forelse($registrations as $r)
<tr><td>{{ $r->first_name }} {{ $r->last_name }}</td><td>{{ $r->status?->name }}</td><td>{{ $r->teamSeason?->label }}</td><td><a href="{{ route('clubunio.portal.registration', ['tenant'=>$tenant->slug, 'registration'=>$r->id]) }}" title="Anmeldung öffnen" aria-label="Anmeldung öffnen">@svg('heroicon-o-chevron-right', 'action-icon')</a></td></tr>
@empty <tr><td colspan="4">Keine Anmeldungen vorhanden.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $registrations->links('pagination::simple-default') }}</div>
@endsection
