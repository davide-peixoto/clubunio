@extends('clubunio.planning.layout')
@section('content')
<h1>Neuer Trainingsplan</h1>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'create'))
<form class="toolbar" method="get"><label>Saison<select name="season">@foreach($seasons as $season)<option value="{{ $season->id }}" @selected($selectedSeason === $season->id)>{{ $season->name }}</option>@endforeach</select></label><button type="submit">Saison wählen</button></form>
<form method="post" action="{{ route('clubunio.training.generate',$tenant->slug) }}" class="planning-form">@csrf @include('clubunio.workspace.context')<input type="hidden" name="request_key" value="{{ $requestKey }}">
<label class="wide">Mannschaft<select name="team_season_id" required>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id') == $team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>Dauer (Minuten)<input type="number" name="durationMinutes" min="30" max="120" value="{{ old('durationMinutes',90) }}" required></label>
<label>Anzahl Spieler<input type="number" name="playerCount" min="4" max="30" value="{{ old('playerCount',18) }}" required></label>
<label class="wide">Schwerpunkt<input name="goal" maxlength="200" value="{{ old('goal') }}" required></label>
<label>Spielfeld / Platzgrösse<input name="fieldSize" maxlength="120" value="{{ old('fieldSize','Halbes Spielfeld') }}" required></label>
<label>Material<input name="material" maxlength="500" value="{{ old('material','Bälle, Hütchen, Überziehleibchen, Tore') }}" required></label>
<div class="actions wide"><button class="primary" type="submit" @disabled($teams->isEmpty())>@svg('heroicon-o-sparkles') Trainingsplan erstellen</button><a href="{{ route('clubunio.training.index',$tenant->slug) }}">Abbrechen</a></div>
</form>
@endif
@endsection
