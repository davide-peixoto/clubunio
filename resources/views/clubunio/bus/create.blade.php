@extends('clubunio.bus.layout')
@section('content')
<h1>Reservation erfassen</h1>
<form method="post" action="{{ route('clubunio.bus.store',$tenant->slug) }}" class="planning-form">@csrf @include('clubunio.workspace.context')
<label>Bus<select name="bus_id" required>@foreach($buses->where('active',true) as $bus)<option value="{{ $bus->id }}" @selected(old('bus_id')==$bus->id)>{{ $bus->name }}</option>@endforeach</select></label>
<label>Mannschaft<select name="team_season_id" required><option value="">Bitte wählen</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id')==$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>Antragsteller<select name="member_id" required>@if($members->count()>1)<option value="">Bitte wählen</option>@endif @foreach($members as $member)<option value="{{ $member->id }}" @selected(old('member_id')==$member->id)>{{ $member->last_name }} {{ $member->first_name }}</option>@endforeach</select></label>
@if(app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor, $tenant, 'bus', 'approve'))<label>Status<select name="status">@foreach(['GENEHMIGT','ANGEFRAGT'] as $code)@if($statuses[$code]->active??false)<option value="{{ $code }}" @selected(old('status','GENEHMIGT')===$code)>{{ $statuses[$code]->name }}</option>@endif @endforeach</select></label>@else<input type="hidden" name="status" value="ANGEFRAGT">@endif
<label>Von<input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" required></label><label>Bis<input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" required></label>
<label class="wide">Ziel<input name="location" maxlength="255" value="{{ old('location') }}"></label><label class="wide">Bemerkung<textarea name="description" rows="3" maxlength="1000">{{ old('description') }}</textarea></label>
<div class="actions wide"><button class="primary" type="submit">@svg('heroicon-o-check') Speichern</button><a href="{{ route('clubunio.bus.index',$tenant->slug) }}">Abbrechen</a></div>
</form>
@endsection
