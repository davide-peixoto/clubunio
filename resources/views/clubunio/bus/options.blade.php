@extends('clubunio.bus.layout')
@section('content')
@php($busPermissions = app(\App\ClubUnio\Services\ModulePermissionService::class))
@php($canUpdate = $busPermissions->allows($actor, $tenant, 'bus', 'update'))
<h1>Busse und Status</h1>
@foreach(['bus'=>['Busse',$buses],'status'=>['Reservierungsstatus',$statuses]] as $kind=>[$heading,$items])
<section><h2>{{ $heading }}</h2>
@foreach($items as $item)
@if($canUpdate)
@php($canChangeActive = $busPermissions->allows($actor, $tenant, 'bus', $item->active ? 'deactivate' : 'restore'))
<form class="toolbar" method="post" action="{{ route('clubunio.bus.options',$tenant->slug) }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="id" value="{{ $item->id }}"><input type="hidden" name="version" value="{{ \App\ClubUnio\Services\BusReservationService::optionVersion($item) }}">
<label>Bezeichnung<input name="name" value="{{ $item->name }}" required maxlength="160"></label>
@if($kind==='status')<span>{{ $item->code }}</span>@endif
<input type="hidden" name="active" value="{{ $canChangeActive ? 0 : (int) $item->active }}"><label><input type="checkbox" name="active" value="1" @checked($item->active) @disabled(!$canChangeActive)> Aktiv</label>
<button class="icon-button" title="Speichern" aria-label="{{ $item->name }} speichern">@svg('heroicon-o-check')</button>
</form>
@else
<p>{{ $item->name }}{{ $kind === 'status' ? ' · '.$item->code : '' }} · {{ $item->active ? 'Aktiv' : 'Inaktiv' }}</p>
@endif
@endforeach</section>
@endforeach
@if($busPermissions->allows($actor, $tenant, 'bus', 'create'))
<section><h2>Neuer Bus</h2><form class="toolbar" method="post" action="{{ route('clubunio.bus.options',$tenant->slug) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="kind" value="bus"><input type="hidden" name="active" value="1"><label>Bezeichnung<input name="name" required maxlength="160" value=""></label><button class="primary">@svg('heroicon-o-plus') Bus hinzufügen</button></form></section>
@endif
@endsection
