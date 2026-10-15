@extends('clubunio.public.layout')
@section('title', 'Info-Display')
@section('head')
    <meta http-equiv="refresh" content="60">
    @livewireStyles
@endsection
@section('content')
<h1>Info-Display <small>{{ now('Europe/Zurich')->format('d.m.Y H:i') }}</small></h1>
<div class="display-grid"><div>
<section><h2>Spielplan heute</h2><div class="table-scroll"><table><thead><tr><th>Zeit</th><th>Gastgeber</th><th>Garderobe</th><th>Gast</th><th>Garderobe</th><th>Spielfeld</th><th>Bemerkungen</th></tr></thead><tbody>
@forelse($competitions as $game)<tr><td>{{ substr($game->starts_at??'',0,5)?:'–' }}</td><td><x-clubunio.club-logo :url="$clubs->get($game->host_club_number)?->logo_url" /> {{ $game->host_name }} {{ $game->host_category }}</td><td>{{ $game->hostRoom?->name }} {{ $game->hostSide?->name }}</td><td><x-clubunio.club-logo :url="$clubs->get($game->guest_club_number)?->logo_url" /> {{ $game->guest_name }} {{ $game->guest_category }}</td><td>{{ $game->guestRoom?->name }} {{ $game->guestSide?->name }}</td><td>{{ $game->field?->name }}</td><td>{{ $game->remarks }}</td></tr>
@empty<tr><td colspan="7">Heute keine Spiele.</td></tr>@endforelse
</tbody></table></div></section>
<section><h2>Trainingsplan heute</h2><form method="get" class="period-form"><label>Trainingsperiode<select name="period_id" onchange="this.form.submit()"><option value="">Bitte wählen</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string)$periodId===(string)$period->id)>{{ $period->name }}</option>@endforeach</select></label><noscript><button>Auswählen</button></noscript></form>
<div class="table-scroll"><table><thead><tr><th>Zeit</th><th>Mannschaft</th><th>Garderobe</th><th>Ort</th></tr></thead><tbody>
@forelse($trainings as $training)<tr><td>{{ substr($training->starts_at,0,5) }} – {{ substr($training->ends_at,0,5) }}</td><td>{{ $training->teamSeason?->label }}</td><td>{{ $training->room?->name }} {{ $training->side?->name }}</td><td>{{ $training->hall ?: $training->field?->name }}</td></tr>@empty<tr><td colspan="4">{{ $periodId?'Heute keine Trainings für diese Auswahl.':'Bitte Trainingsperiode wählen.' }}</td></tr>@endforelse
</tbody></table></div></section>
<section><h2>Anlagenstatus</h2>@forelse($fields as $field)<p><strong>{{ $field->field?->name }}</strong> · {{ $field->status?->active ? $field->status->name:'Unbekannt' }} {{ $field->note }}</p>@empty<p>Kein Anlagenstatus erfasst.</p>@endforelse</section>
<section><h2>Meldungen</h2>@forelse($notices as $notice)<article><h3>{{ $notice->title }}</h3><p class="multiline">{{ $notice->body }}</p></article>@empty<p>Keine Meldungen.</p>@endforelse</section>
</div><aside><livewire:clubunio.signage-feeds :tenant-id="$tenant->id" :public-display="true" /></aside></div>
@endsection
@section('scripts') @livewireScripts @endsection
