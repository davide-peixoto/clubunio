@extends('clubunio.bus.layout')
@section('content')
<h1>Reservation umbuchen</h1>
<form method="post" class="planning-form" action="{{ route('clubunio.bus.reschedule',[$tenant->slug,$row->id]) }}">@csrf
<input type="hidden" name="revision" value="{{ $row->revision }}">
<label>Bus<select name="bus_id" required>@foreach($buses->where('active',true) as $bus)<option value="{{ $bus->id }}" @selected(old('bus_id',$row->bus_id)==$bus->id)>{{ $bus->name }}</option>@endforeach</select></label>
<label>Von<input type="datetime-local" name="starts_at" required value="{{ old('starts_at',\Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('Y-m-d\TH:i')) }}"></label>
<label>Bis<input type="datetime-local" name="ends_at" required value="{{ old('ends_at',\Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('Y-m-d\TH:i')) }}"></label>
<label>Ziel<input name="location" maxlength="255" value="{{ old('location',$row->location) }}"></label>
<label class="wide">Bemerkung<textarea name="description" maxlength="1000">{{ old('description',$row->description) }}</textarea></label>
<label class="wide">Änderungsgrund<textarea name="reason" required maxlength="1000">{{ old('reason') }}</textarea></label>
<div class="actions wide"><button class="primary">@svg('heroicon-o-check') Umbuchung speichern</button><a href="{{ route('clubunio.bus.show',[$tenant->slug,$row->id]) }}">Abbrechen</a></div>
</form>
@endsection
