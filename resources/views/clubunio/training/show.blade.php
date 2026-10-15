@extends('clubunio.planning.layout')
@section('content')
<div class="page-heading"><h1>{{ $row->title }}</h1><a class="primary" href="{{ route('clubunio.training.pdf',[$tenant->slug,$row->id]) }}" target="_blank" rel="noopener">@svg('heroicon-o-document-arrow-down') PDF</a></div>
<p>{{ $row->input['teamName'] }} · {{ $row->input['durationMinutes'] }} Minuten · {{ $row->input['playerCount'] }} Spieler · Revision {{ $row->revision }}</p>
@if($row->archived_at)<p role="status">Archiviert</p>@endif
@if($row->legacy_source_id ?? false)<p role="status">Übernommener Altplan: Die Spielfeldgrafiken sind schematische Darstellungen, keine Originalgrafiken.</p>@endif
@if($row->legacy_quality_pending ?? false)<p role="status">Die aktuelle SV-Sissach-/SFV-Qualitätsprüfung steht aus. Der Plan bleibt privat; vor einer Freigabe ist eine geprüfte Bearbeitung erforderlich.</p>@endif
<div class="toolbar">
<form method="post" action="{{ route('clubunio.training.copy',[$tenant->slug,$row->id]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}"><input type="hidden" name="request_key" value="{{ $requestKey }}"><button>@svg('heroicon-o-document-duplicate') Private Kopie</button></form>
@if($canManage)<form method="post" action="{{ route('clubunio.training.archive',[$tenant->slug,$row->id]) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}"><input type="hidden" name="archived" value="{{ $row->archived_at ? '0' : '1' }}"><button>@svg('heroicon-o-archive-box') {{ $row->archived_at ? 'Wiederherstellen' : 'Archivieren' }}</button></form>@endif
</div>
@if($review && $editable)
<details open><summary>Prüfvorschlag</summary>@include('clubunio.training.content',['content'=>$review['plan']])<form method="post" action="{{ route('clubunio.training.apply',[$tenant->slug,$row->id]) }}">@csrf @include('clubunio.workspace.context')<button class="primary">@svg('heroicon-o-check') Vorschlag übernehmen</button></form></details>
@endif
@include('clubunio.training.content',['content'=>$row->plan])
@if($editable)
<details @if($errors->any()) open @endif><summary>Trainingsplan bearbeiten</summary>
<form method="post" action="{{ route('clubunio.training.save',[$tenant->slug,$row->id]) }}" class="planning-form">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}">
<label class="wide">Titel<input name="plan[title]" value="{{ old('plan.title',$row->plan['title']) }}" maxlength="160" required></label>
<label class="wide">Schwerpunkt<textarea name="plan[focus]" rows="3" required>{{ old('plan.focus',$row->plan['focus']) }}</textarea></label>
<label class="wide">Prinzipien<textarea name="plan[principles]" rows="4" required>{{ old('plan.principles',implode("\n",$row->plan['principles'])) }}</textarea></label>
@foreach($row->plan['exercises'] as $i=>$exercise)
<h3 class="wide">Übung {{ $i+1 }}</h3>
<label>Phase<select name="plan[exercises][{{ $i }}][phase]">@foreach(['Einstieg','Hauptteil','Abschluss'] as $phase)<option @selected(old("plan.exercises.$i.phase",$exercise['phase']) === $phase)>{{ $phase }}</option>@endforeach</select></label>
<label>Minuten<input type="number" min="1" max="120" name="plan[exercises][{{ $i }}][durationMinutes]" value="{{ old("plan.exercises.$i.durationMinutes",$exercise['durationMinutes']) }}" required></label>
@foreach(['title'=>'Titel','setup'=>'Aufbau','flow'=>'Ablauf','coachingPoints'=>'Coaching','easier'=>'Einfacher','harder'=>'Schwieriger','diagramDescription'=>'Spielfeldaufteilung'] as $field=>$label)
<label class="wide">{{ $label }}<textarea rows="3" name="plan[exercises][{{ $i }}][{{ $field }}]" required>{{ old("plan.exercises.$i.$field",is_array($exercise[$field]) ? implode("\n",$exercise[$field]) : $exercise[$field]) }}</textarea></label>
@endforeach
@endforeach
<label class="wide">Qualitätsprüfung<textarea name="plan[qualityChecks]" rows="4" required>{{ old('plan.qualityChecks',implode("\n",$row->plan['qualityChecks'])) }}</textarea></label>
<label class="wide"><input type="checkbox" name="shared" value="1" @checked(old('shared',$row->shared))> Für zuständige Teamverantwortliche freigeben</label>
<button class="primary" type="submit">@svg('heroicon-o-check') Speichern</button></form></details>
<form method="post" action="{{ route('clubunio.training.review',[$tenant->slug,$row->id]) }}" class="training-review">@csrf @include('clubunio.workspace.context')<input type="hidden" name="revision" value="{{ $row->revision }}"><input type="hidden" name="request_key" value="{{ $requestKey }}"><button>@svg('heroicon-o-sparkles') Gespeicherten Plan prüfen lassen</button></form>
@endif
@endsection
