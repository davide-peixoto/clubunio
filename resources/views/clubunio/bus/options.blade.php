@extends('clubunio.bus.layout')
@section('content')
@php($workspaceComponents = (bool) request()->attributes->get('clubunioWorkspace'))
@php($busPermissions = app(\App\ClubUnio\Services\ModulePermissionService::class))
@php($canUpdate = $busPermissions->allows($actor, $tenant, 'bus', 'update'))
<h1>{{ __('Busse und Status') }}</h1>
@foreach(['bus'=>[__('Busse'),$buses],'status'=>[__('Reservierungsstatus'),$statuses]] as $kind=>[$heading,$items])
<section><h2>{{ $heading }}</h2>
@foreach($items as $item)
@php($itemLabel = $kind === 'status' ? app(\App\ClubUnio\Services\LookupTranslations::class)->label('bus_statuses', $item, tenant: $tenant->id) : $item->name)
@if($canUpdate)
@php($canChangeActive = $busPermissions->allows($actor, $tenant, 'bus', $item->active ? 'deactivate' : 'restore'))
<form class="toolbar" method="post" action="{{ route('clubunio.bus.options',$tenant->slug) }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="kind" value="{{ $kind }}"><input type="hidden" name="id" value="{{ $item->id }}"><input type="hidden" name="version" value="{{ \App\ClubUnio\Services\BusReservationService::optionVersion($item) }}">
<label>{{ __('Bezeichnung') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="name" value="{{ $item->name }}" required maxlength="160" /></x-filament::input.wrapper>@else<input name="name" value="{{ $item->name }}" required maxlength="160">@endif</label>
@if($kind==='status')<span>{{ $item->code }}</span>@endif
<input type="hidden" name="active" value="{{ $canChangeActive ? 0 : (int) $item->active }}"><label>@if($workspaceComponents)<x-filament::input.checkbox name="active" value="1" :checked="(bool) $item->active" :disabled="!$canChangeActive" />@else<input type="checkbox" name="active" value="1" @checked($item->active) @disabled(!$canChangeActive)>@endif {{ __('Aktiv') }}</label>

@if($workspaceComponents)<x-filament::icon-button title="{{ __('Speichern') }}" label="{{ __(':name speichern', ['name' => $itemLabel]) }}" icon="heroicon-o-check" type="submit" color="gray" />@else<button class="icon-button" title="{{ __('Speichern') }}" aria-label="{{ __(':name speichern', ['name' => $itemLabel]) }}">@svg('heroicon-o-check')</button>@endif
</form>
@else
<p>{{ $itemLabel }}{{ $kind === 'status' ? ' · '.$item->code : '' }} · {{ $item->active ? __('Aktiv') : __('Inaktiv') }}</p>
@endif
@endforeach</section>
@endforeach
@if($busPermissions->allows($actor, $tenant, 'bus', 'create'))
<section><h2>{{ __('Neuer Bus') }}</h2><form class="toolbar" method="post" action="{{ route('clubunio.bus.options',$tenant->slug) }}">@csrf @include('clubunio.workspace.context')<input type="hidden" name="kind" value="bus"><input type="hidden" name="active" value="1"><label>{{ __('Bezeichnung') }}
@if($workspaceComponents)<x-filament::input.wrapper><x-filament::input name="name" required maxlength="160" value="" /></x-filament::input.wrapper>@else<input name="name" required maxlength="160" value="">@endif</label>
@if($workspaceComponents)<x-filament::button icon="heroicon-o-plus" type="submit" color="primary">{{ __('Bus hinzufügen') }}</x-filament::button>@else<button class="primary">@svg('heroicon-o-plus') {{ __('Bus hinzufügen') }}</button>@endif</form></section>
@endif
@endsection
