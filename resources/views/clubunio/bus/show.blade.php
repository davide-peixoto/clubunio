@extends('clubunio.bus.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
@php($busPermissions = app(\App\ClubUnio\Services\ModulePermissionService::class))
@php($canApprove = $busPermissions->allows($actor, $tenant, 'bus', 'approve'))
@php($canCancelReservation = $canCancel && ($row->status === 'ANGEFRAGT' || $busPermissions->allows($actor, $tenant, 'bus', 'cancel')))
<div class="page-heading"><h1>{{ $buses->firstWhere('id',$row->bus_id)?->name }} · {{ \Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y') }}</h1>@include('clubunio.bus.badge',['code'=>$row->status])</div>
<dl class="planning-facts"><div><dt>Von</dt><dd>{{ \Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</dd></div><div><dt>Bis</dt><dd>{{ \Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</dd></div><div><dt>Mannschaft</dt><dd>{{ $team??'Nicht zugeordnet' }}</dd></div><div><dt>Antragsteller</dt><dd>{{ $member ? $member->first_name.' '.$member->last_name : 'Nicht zugeordnet' }}</dd></div><div><dt>Ziel</dt><dd>{{ $row->location?:'–' }}</dd></div></dl>
@if($row->description)<h2>Bemerkung</h2><p class="pre-line">{{ $row->description }}</p>@endif
@if($row->decision_note)<h2>Entscheid</h2><p class="pre-line">{{ $row->decision_note }}</p>@endif
@if($canReschedule)
@if($workspaceComponents)<x-filament::button href="{{ route('clubunio.bus.edit',[$tenant->slug,$row->id]) }}" icon="heroicon-o-pencil-square" tag="a" color="primary">Umbuchen</x-filament::button>@else<a class="primary" href="{{ route('clubunio.bus.edit',[$tenant->slug,$row->id]) }}">@svg('heroicon-o-pencil-square') Umbuchen</a>@endif@endif
@if($busPermissions->allows($actor, $tenant, 'bus', 'update') && $row->legacy_id && (!$row->member_id || !$row->team_season_id))
@if($workspaceComponents)<x-filament::button href="{{ route('clubunio.bus.assignment',[$tenant->slug,$row->id]) }}" icon="heroicon-o-link" tag="a" color="primary">Historische Zuordnung</x-filament::button>@else<a class="primary" href="{{ route('clubunio.bus.assignment',[$tenant->slug,$row->id]) }}">@svg('heroicon-o-link') Historische Zuordnung</a>@endif@endif
@if($keybox['eligible'])<section><h2>Schlüsselbox</h2><p>Zugangsfenster: {{ $keybox['from']->format('d.m.Y H:i') }} – {{ $keybox['until']->format('d.m.Y H:i') }}</p><p>{{ $keybox['inWindow'] ? 'Zeitfenster aktiv.' : 'Ausserhalb des Zugangsfensters.' }} Code-Ausgabe im ClubUnio-Testbetrieb gesperrt.</p></section>@endif
@if($keybox['simulationEnabled'])<section><h2>Schlüsselbox-Simulation</h2><p>Keine echte Geräteverbindung. Der Testcode öffnet keine Schlüsselbox.</p>
@if($keybox['testCode'])<output>{{ $keybox['testCode'] }}</output>@elseif($keybox['inWindow'])<form method="post" action="{{ route('clubunio.bus.keybox.simulation',[$tenant->slug,$row->id]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}">
@if($workspaceComponents)<x-filament::button icon="heroicon-o-beaker" type="submit" color="primary">Testcode erzeugen</x-filament::button>@else<button class="primary">@svg('heroicon-o-beaker') Testcode erzeugen</button>@endif</form>@endif</section>@endif
@if(\Carbon\Carbon::parse($row->ends_at)->isFuture() && in_array($row->status,['ANGEFRAGT','GENEHMIGT','UNBEKANNT'],true))
@if(($canApprove && $row->status !== 'GENEHMIGT') || $canCancelReservation)
<form method="post" action="{{ route('clubunio.bus.decide',[$tenant->slug,$row->id]) }}" class="planning-form">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}">
<label class="wide">Entscheidbemerkung<textarea name="note" maxlength="1000" rows="3">{{ old('note') }}</textarea></label><div class="actions wide">
@if($canApprove && $row->status!=='GENEHMIGT')

@if($workspaceComponents)<x-filament::button name="status" value="GENEHMIGT" icon="heroicon-o-check" type="submit" color="primary">Genehmigen</x-filament::button>@else<button class="primary" name="status" value="GENEHMIGT">@svg('heroicon-o-check') Genehmigen</button>@endif
@if($workspaceComponents)<x-filament::button name="status" value="ABGELEHNT" icon="heroicon-o-x-mark" type="submit" color="gray">Ablehnen</x-filament::button>@else<button name="status" value="ABGELEHNT">@svg('heroicon-o-x-mark') Ablehnen</button>@endif
@endif @if($canCancelReservation)
@if($workspaceComponents)<x-filament::button name="status" value="STORNIERT" icon="heroicon-o-no-symbol" type="submit" color="gray">Stornieren</x-filament::button>@else<button name="status" value="STORNIERT">@svg('heroicon-o-no-symbol') Stornieren</button>@endif@endif</div></form>
@endif @endif
<a href="{{ route('clubunio.bus.index',$tenant->slug) }}">Zurück zur Übersicht</a>
@endsection
