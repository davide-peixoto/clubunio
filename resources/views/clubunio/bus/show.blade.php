@extends('clubunio.bus.layout')
@section('content')
<div class="page-heading"><h1>{{ $buses->firstWhere('id',$row->bus_id)?->name }} · {{ \Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y') }}</h1>@include('clubunio.bus.badge',['code'=>$row->status])</div>
<dl class="planning-facts"><div><dt>Von</dt><dd>{{ \Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</dd></div><div><dt>Bis</dt><dd>{{ \Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</dd></div><div><dt>Mannschaft</dt><dd>{{ $team??'Nicht zugeordnet' }}</dd></div><div><dt>Antragsteller</dt><dd>{{ $member ? $member->first_name.' '.$member->last_name : 'Nicht zugeordnet' }}</dd></div><div><dt>Ziel</dt><dd>{{ $row->location?:'–' }}</dd></div></dl>
@if($row->description)<h2>Bemerkung</h2><p class="pre-line">{{ $row->description }}</p>@endif
@if($row->decision_note)<h2>Entscheid</h2><p class="pre-line">{{ $row->decision_note }}</p>@endif
@if($canReschedule)<a class="primary" href="{{ route('clubunio.bus.edit',[$tenant->slug,$row->id]) }}">@svg('heroicon-o-pencil-square') Umbuchen</a>@endif
@if($actor->canAccessTenant($tenant) && $row->legacy_id && (!$row->member_id || !$row->team_season_id))<a class="primary" href="{{ route('clubunio.bus.assignment',[$tenant->slug,$row->id]) }}">@svg('heroicon-o-link') Historische Zuordnung</a>@endif
@if($keybox['eligible'])<section><h2>Schlüsselbox</h2><p>Zugangsfenster: {{ $keybox['from']->format('d.m.Y H:i') }} – {{ $keybox['until']->format('d.m.Y H:i') }}</p><p>{{ $keybox['inWindow'] ? 'Zeitfenster aktiv.' : 'Ausserhalb des Zugangsfensters.' }} Code-Ausgabe im ClubUnio-Testbetrieb gesperrt.</p></section>@endif
@if(\Carbon\Carbon::parse($row->ends_at)->isFuture() && in_array($row->status,['ANGEFRAGT','GENEHMIGT','UNBEKANNT'],true))
@if($actor->canAccessTenant($tenant) || ($canCancel && $row->status==='ANGEFRAGT'))
<form method="post" action="{{ route('clubunio.bus.decide',[$tenant->slug,$row->id]) }}" class="planning-form">@csrf<input type="hidden" name="revision" value="{{ $row->revision }}">
<label class="wide">Entscheidbemerkung<textarea name="note" maxlength="1000" rows="3">{{ old('note') }}</textarea></label><div class="actions wide">
@if($actor->canAccessTenant($tenant) && $row->status!=='GENEHMIGT')
<button class="primary" name="status" value="GENEHMIGT">@svg('heroicon-o-check') Genehmigen</button><button name="status" value="ABGELEHNT">@svg('heroicon-o-x-mark') Ablehnen</button>
@endif<button name="status" value="STORNIERT">@svg('heroicon-o-no-symbol') Stornieren</button></div></form>
@endif @endif
<a href="{{ route('clubunio.bus.index',$tenant->slug) }}">Zurück zur Übersicht</a>
@endsection
