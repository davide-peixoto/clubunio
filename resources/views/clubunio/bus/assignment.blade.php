@extends('clubunio.bus.layout')
@section('content')
<h1>Historische Zuordnung</h1>
<form method="post" action="{{ route('clubunio.bus.assign',[$tenant->slug,$row->id]) }}" class="planning-form">
@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}">
<label>Mannschaft / Saison<select name="team_season_id" required><option value="">Bitte wählen</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id',$row->team_season_id) == $team->id) @disabled($row->team_season_id && $row->team_season_id != $team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>Antragsteller<select name="member_id" required><option value="">Bitte wählen</option>@foreach($members as $member)<option value="{{ $member->id }}" @selected(old('member_id',$row->member_id) == $member->id) @disabled($row->member_id && $row->member_id != $member->id)>{{ $member->last_name }} {{ $member->first_name }} ({{ $member->legacy_id ?? $member->id }})</option>@endforeach</select></label>
<label class="wide">Begründung<textarea name="reason" minlength="3" maxlength="1000" required>{{ old('reason') }}</textarea></label>
<button class="primary">@svg('heroicon-o-check') Zuordnung speichern</button>
</form>
@endsection
