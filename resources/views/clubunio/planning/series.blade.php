@extends('clubunio.planning.layout')
@section('content')
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>Serie bearbeiten</h1><h2>{{ $record->title }}</h2>
@if($profileAllows('update'))<form method="post" class="planning-form">@csrf @include('clubunio.workspace.context')
<label>Termine ab<input type="date" name="from" required value="{{ old('from',$preview['input']['from']??now('Europe/Zurich')->toDateString()) }}"></label>
<label>Verschiebung in Minuten<input type="number" name="shift_minutes" min="-1440" max="1440" required value="{{ old('shift_minutes',$preview['input']['shift_minutes']??0) }}"></label>
<label><input type="checkbox" name="change_location" value="1" @checked($preview['input']['change_location']??false)> Ort ändern</label>
<label>Neuer Ort<input name="location" maxlength="255" value="{{ old('location',$preview['input']['location']??$record->location) }}"></label>
<label><input type="checkbox" name="change_title" value="1" @checked($preview['input']['change_title']??false)> Titel ändern</label><label>Neuer Titel<input name="title" maxlength="255" value="{{ old('title',$preview['input']['title']??$record->title) }}"></label>
<label><input type="checkbox" name="change_capacity" value="1" @checked($preview['input']['change_capacity']??false)> Kapazität ändern</label><label>Maximale Teilnehmerzahl<input type="number" name="capacity" min="1" max="1000" value="{{ old('capacity',$preview['input']['capacity']??$record->capacity) }}"></label>
@if($profileAllows('cancel'))<label><input type="checkbox" name="cancel" value="1" @checked($preview['input']['cancel']??false)> Termine absagen</label>@endif
<div class="wide actions"><button type="submit">Vorschau</button></div></form>@endif
@if($preview)
<h2>{{ count($preview['changes']) }} Änderungen</h2><p>{{ $preview['skipped'] }} nicht betroffene oder geschützte Termine bleiben unverändert.</p>
<div class="table-scroll"><table><thead><tr><th>Bisher</th><th>Titel</th><th>Neuer Beginn</th><th>Neue Frist</th><th>Ort</th><th>Plätze</th><th>Status</th></tr></thead><tbody>
@foreach($preview['changes'] as $change)<tr><td>{{ \Carbon\Carbon::parse($change['before']->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $change['input']['title'] }}</td><td>{{ str_replace('T',' ',$change['input']['starts_at']) }}</td><td>{{ str_replace('T',' ',$change['input']['deadline_at']) }}</td><td>{{ $change['input']['location'] }}</td><td>{{ $change['input']['capacity']??'Unbegrenzt' }}</td><td>@include('clubunio.planning.badge',['category'=>'status','code'=>$change['input']['status']])</td></tr>@endforeach
</tbody></table></div>
@if(count($preview['changes']) && $profileAllows('update') && (empty($preview['input']['cancel']) || $profileAllows('cancel')))<form method="post" action="{{ route('clubunio.planning.series.apply',[$tenant->slug,$record->id]) }}">@csrf @include('clubunio.workspace.context')
@foreach($preview['input'] as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<button class="primary" type="submit">{{ count($preview['changes']) }} Änderungen bestätigen</button></form>@endif
@endif
@endsection
