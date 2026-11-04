@extends('clubunio.planning.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
<h1>{{ __('Neuer Trainingsplan') }}</h1>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'create'))
<form class="toolbar" method="get"><label>{{ __('Saison') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input.select name="season">@foreach($seasons as $season)<option value="{{ $season->id }}" @selected($selectedSeason === $season->id)>{{ $season->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@else<select name="season">@foreach($seasons as $season)<option value="{{ $season->id }}" @selected($selectedSeason === $season->id)>{{ $season->name }}</option>@endforeach</select>@endif</label>
@if($workspaceComponents)<x-filament::button type="submit" color="gray">{{ __('Saison wählen') }}</x-filament::button>@else<button type="submit">{{ __('Saison wählen') }}</button>@endif</form>
<form method="post" action="{{ route('clubunio.training.generate',$tenant->slug) }}" class="planning-form">@csrf @include('clubunio.workspace.context')<input type="hidden" name="request_key" value="{{ $requestKey }}">
<label class="wide">{{ __('Mannschaft') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input.select name="team_season_id" required>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id') == $team->id)>{{ $team->label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>@else<select name="team_season_id" required>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(old('team_season_id') == $team->id)>{{ $team->label }}</option>@endforeach</select>@endif</label>
<label>{{ __('Dauer (Minuten)') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="durationMinutes" min="30" max="120" value="{{ old('durationMinutes',90) }}" required /></x-filament::input.wrapper>@else<input type="number" name="durationMinutes" min="30" max="120" value="{{ old('durationMinutes',90) }}" required>@endif</label>
<label>{{ __('Anzahl Spieler') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="playerCount" min="4" max="30" value="{{ old('playerCount',18) }}" required /></x-filament::input.wrapper>@else<input type="number" name="playerCount" min="4" max="30" value="{{ old('playerCount',18) }}" required>@endif</label>
<label class="wide">{{ __('Schwerpunkt') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="goal" maxlength="200" value="{{ old('goal') }}" required /></x-filament::input.wrapper>@else<input name="goal" maxlength="200" value="{{ old('goal') }}" required>@endif</label>
<label>{{ __('Spielfeld / Platzgrösse') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="fieldSize" maxlength="120" value="{{ old('fieldSize',__('Halbes Spielfeld')) }}" required /></x-filament::input.wrapper>@else<input name="fieldSize" maxlength="120" value="{{ old('fieldSize',__('Halbes Spielfeld')) }}" required>@endif</label>
<label>{{ __('Material') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="material" maxlength="500" value="{{ old('material',__('Bälle, Hütchen, Überziehleibchen, Tore')) }}" required /></x-filament::input.wrapper>@else<input name="material" maxlength="500" value="{{ old('material',__('Bälle, Hütchen, Überziehleibchen, Tore')) }}" required>@endif</label>
<div class="actions wide">
@if($workspaceComponents)<x-filament::button type="submit" :disabled="$teams->isEmpty()" icon="heroicon-o-sparkles" color="primary">{{ __('Trainingsplan erstellen') }}</x-filament::button>@else<button class="primary" type="submit" @disabled($teams->isEmpty())>@svg('heroicon-o-sparkles') {{ __('Trainingsplan erstellen') }}</button>@endif<a href="{{ route('clubunio.training.index',$tenant->slug) }}">{{ __('Abbrechen') }}</a></div>
</form>
@endif
@endsection
