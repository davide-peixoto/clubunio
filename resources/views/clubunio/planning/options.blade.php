@extends('clubunio.planning.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<h1>Auswahlwerte</h1>
@foreach($options as $category=>$rows)<h2>{{ ['kind'=>'Terminarten','mode'=>'Teilnahmemodus','status'=>'Terminstatus','participation'=>'Teilnahmestatus','attendance'=>'Anwesenheit','absence'=>'Absenzarten'][$category]??$category }}</h2>
@foreach($rows as $option)
@if($profileAllows('update'))<form class="toolbar" method="post">@csrf @include('clubunio.workspace.context')<input type="hidden" name="id" value="{{ $option->id }}"><input type="hidden" name="category" value="{{ $category }}"><input type="hidden" name="code" value="{{ $option->code }}">
<label>Code
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input readonly value="{{ $option->code }}" /></x-filament::input.wrapper>@else<input readonly value="{{ $option->code }}">@endif</label><label>Bezeichnung
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="name" value="{{ $option->name }}" required maxlength="100" /></x-filament::input.wrapper>@else<input name="name" value="{{ $option->name }}" required maxlength="100">@endif</label><label>Reihenfolge
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input type="number" name="sort_order" min="0" max="10000" value="{{ $option->sort_order }}" /></x-filament::input.wrapper>@else<input type="number" name="sort_order" min="0" max="10000" value="{{ $option->sort_order }}">@endif</label>
@php($canToggle = $profileAllows($option->active ? 'deactivate' : 'restore'))
<input type="hidden" name="active" value="{{ $canToggle ? 0 : (int) $option->active }}"><label><input type="checkbox" name="active" value="1" @checked($option->active) @disabled(!$canToggle)> Aktiv</label>
@if($workspaceComponents)<x-filament::icon-button title="Speichern" label="Speichern" icon="heroicon-o-check" type="submit" color="gray" />@else<button class="icon-button" title="Speichern" aria-label="Speichern">@svg('heroicon-o-check')</button>@endif</form>
@else<p>{{ $option->code }} · {{ $option->name }} · {{ $option->sort_order }} · {{ $option->active ? 'Aktiv' : 'Inaktiv' }}</p>@endif
@endforeach @endforeach
@if($profileAllows('create'))<h2>Neue Auswahl</h2><form class="toolbar" method="post">@csrf @include('clubunio.workspace.context')<label>Kategorie
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input.select name="category"><option value="kind">Terminart</option><option value="absence">Absenzart</option></x-filament::input.select></x-filament::input.wrapper>@else<select name="category"><option value="kind">Terminart</option><option value="absence">Absenzart</option></select>@endif</label><label>Code
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="code" pattern="[A-Z_]{2,40}" required /></x-filament::input.wrapper>@else<input name="code" pattern="[A-Z_]{2,40}" required>@endif</label><label>Bezeichnung
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="name" required maxlength="100" /></x-filament::input.wrapper>@else<input name="name" required maxlength="100">@endif</label><input type="hidden" name="active" value="1"><input type="hidden" name="sort_order" value="0">
@if($workspaceComponents)<x-filament::button type="submit" color="primary">Erstellen</x-filament::button>@else<button class="primary">Erstellen</button>@endif</form>@endif
@endsection
