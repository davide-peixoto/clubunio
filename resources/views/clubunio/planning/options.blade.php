@extends('clubunio.planning.layout')
@section('content')
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>Auswahlwerte</h1>
@foreach($options as $category=>$rows)<h2>{{ ['kind'=>'Terminarten','mode'=>'Teilnahmemodus','status'=>'Terminstatus','participation'=>'Teilnahmestatus','attendance'=>'Anwesenheit','absence'=>'Absenzarten'][$category]??$category }}</h2>
@foreach($rows as $option)
@if($profileAllows('update'))<form class="toolbar" method="post">@csrf @include('clubunio.workspace.context')<input type="hidden" name="id" value="{{ $option->id }}"><input type="hidden" name="category" value="{{ $category }}"><input type="hidden" name="code" value="{{ $option->code }}">
<label>Code<input readonly value="{{ $option->code }}"></label><label>Bezeichnung<input name="name" value="{{ $option->name }}" required maxlength="100"></label><label>Reihenfolge<input type="number" name="sort_order" min="0" max="10000" value="{{ $option->sort_order }}"></label>
@php($canToggle = $profileAllows($option->active ? 'deactivate' : 'restore'))
<input type="hidden" name="active" value="{{ $canToggle ? 0 : (int) $option->active }}"><label><input type="checkbox" name="active" value="1" @checked($option->active) @disabled(!$canToggle)> Aktiv</label><button class="icon-button" title="Speichern" aria-label="Speichern">@svg('heroicon-o-check')</button></form>
@else<p>{{ $option->code }} · {{ $option->name }} · {{ $option->sort_order }} · {{ $option->active ? 'Aktiv' : 'Inaktiv' }}</p>@endif
@endforeach @endforeach
@if($profileAllows('create'))<h2>Neue Auswahl</h2><form class="toolbar" method="post">@csrf @include('clubunio.workspace.context')<label>Kategorie<select name="category"><option value="kind">Terminart</option><option value="absence">Absenzart</option></select></label><label>Code<input name="code" pattern="[A-Z_]{2,40}" required></label><label>Bezeichnung<input name="name" required maxlength="100"></label><input type="hidden" name="active" value="1"><input type="hidden" name="sort_order" value="0"><button class="primary">Erstellen</button></form>@endif
@endsection
