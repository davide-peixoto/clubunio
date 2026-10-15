@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><h1>Mitglieder</h1><span>{{ $members->total() }} Einträge</span></div>
<form method="get" class="toolbar" action="{{ route('clubunio.portal.members', ['tenant' => $tenant->slug]) }}">
    <div><label for="q">Suche</label><input type="search" id="q" name="q" value="{{ $q }}" maxlength="120"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="">Alle</option>
        @foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status') === (string)$status->id)>{{ $status->name }}</option>@endforeach
    </select></div>
    <button class="icon-button" type="submit" aria-label="Suchen" title="Suchen">@svg('heroicon-o-magnifying-glass')</button>
    <a class="icon-button" href="{{ route('clubunio.portal.members', ['tenant' => $tenant->slug]) }}" title="Filter zurücksetzen" aria-label="Filter zurücksetzen">@svg('heroicon-o-x-mark')</a>
</form>
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
<div class="table-scroll"><table><thead><tr><th>Name</th><th>Status</th><th>Ort</th><th>E-Mail</th><th>Mobile</th><th>Rechnungen</th></tr></thead><tbody>
@forelse($members as $member)
    <tr><td>{{ $member->company ?: trim($member->first_name.' '.$member->last_name) }}</td><td>{{ $member->status?->name }}</td>
        <td>{{ $member->city }}</td><td>{{ $member->email }}</td><td>{{ $member->mobile }}</td><td><a class="icon-button" href="{{ route('clubunio.portal.invoices',['tenant'=>$tenant->slug,'member'=>$member->id]) }}" title="Rechnungen" aria-label="Rechnungen">@svg('heroicon-o-document-text')</a>
        @if(in_array($member->id,$editableMemberIds))<a class="icon-button" href="{{ route('clubunio.portal.member.edit',['tenant'=>$tenant->slug,'member'=>$member->id]) }}" title="Mitgliederdaten und Dokumente" aria-label="Mitgliederdaten und Dokumente">@svg('heroicon-o-pencil-square')</a>@endif</td></tr>
@empty
    <tr><td colspan="6">Keine Mitglieder gefunden.</td></tr>
@endforelse
</tbody></table></div>
<div class="pagination">{{ $members->links('pagination::simple-default') }}</div>
@endsection
