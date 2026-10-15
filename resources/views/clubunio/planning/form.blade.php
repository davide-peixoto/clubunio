@extends('clubunio.planning.layout')
@section('content')
<h1>{{ isset($sfv) ? 'SFV-Spiel übernehmen' : ($record ? 'Termin bearbeiten' : 'Termin erstellen') }}</h1>
@isset($sfv)<p>Verband: {{ $sfv->state }} · {{ $sfv->match_type }}</p>
@if($teamChoices->count()>1)<form method="get" class="actions"><label>Mannschaft / Saison<select name="team">@foreach($teamChoices as $choice)<option value="{{ $choice->id }}" @selected($choice->id===$record->team_season_id)>{{ $choice->label }}</option>@endforeach</select></label><button type="submit">Auswählen</button></form>@endif
@if($existing)<p class="notice">Bestehender Termin: {{ $existing->title }} · {{ \Carbon\Carbon::parse($existing->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }} · {{ $existing->location }}. Speichern aktualisiert diesen Termin.</p>@endif @endisset
<form class="planning-form" method="post" action="{{ isset($sfv) ? route('clubunio.planning.sfv.import',[$tenant->slug,$sfv->id]) : ($record ? route('clubunio.planning.save',[$tenant->slug,$record->id]) : route('clubunio.planning.store',$tenant->slug)) }}">@csrf @include('clubunio.workspace.context')
@isset($sfv)<input type="hidden" name="sfv_revision" value="{{ $sfv->revision }}"><input type="hidden" name="event_revision" value="{{ $existing->revision??0 }}">@endisset
<input type="hidden" name="revision" value="{{ old('revision',$record->revision??0) }}">
<label>Mannschaft<select name="team_season_id" required>@foreach($teams->whereIn('id',$managed) as $team)@if(!$record || $team->id===$record->team_season_id)<option value="{{ $team->id }}" @selected(old('team_season_id',$record->team_season_id??'')==$team->id)>{{ $team->label }}</option>@endif @endforeach</select></label>
<label>Titel<input name="title" required maxlength="255" value="{{ old('title',$record->title??'') }}"></label>
@foreach(['kind'=>'Terminart','mode'=>'Teilnahme','status'=>'Status'] as $field=>$label)<label>{{ $label }}<select name="{{ $field }}" required>@foreach($options[$field]??[] as $option)@if($option->active || ($record->$field??null)===$option->code)<option value="{{ $option->code }}" @selected(old($field,$record->$field??(['kind'=>'TRAINING','mode'=>'ANMELDEN','status'=>'AKTIV'][$field]))===$option->code)>{{ $option->name }}</option>@endif @endforeach</select></label>@endforeach
@foreach(['starts_at'=>'Beginn','ends_at'=>'Ende','deadline_at'=>'An-/Abmeldefrist'] as $field=>$label)<label>{{ $label }}<input name="{{ $field }}" type="datetime-local" required value="{{ old($field, !empty($record->$field) ? \Carbon\Carbon::parse($record->$field)->tz('Europe/Zurich')->format('Y-m-d\TH:i') : '') }}"></label>@endforeach
<label>Maximale Teilnehmerzahl<input name="capacity" type="number" min="1" max="1000" value="{{ old('capacity',$record ? $record->capacity : 18) }}"></label>
@if(!$record)<label>Wöchentliche Termine (Anzahl)<input name="repeat_count" type="number" min="1" max="26" value="{{ old('repeat_count',1) }}"></label>@endif
<label>Ort<input name="location" maxlength="255" value="{{ old('location',$record->location??'') }}"></label>
<label class="wide">Beschreibung<textarea name="description" rows="4" maxlength="4000">{{ old('description',$record->description??'') }}</textarea></label>
<div class="wide actions"><button class="primary" type="submit">@svg('heroicon-o-check') Speichern</button><a href="{{ route('clubunio.planning.index',$tenant->slug) }}">Abbrechen</a></div></form>
@endsection
