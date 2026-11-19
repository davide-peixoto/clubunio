@extends('clubunio.workspace.layout', ['module' => 'bexio'])
@section('native-content')
<header class="fi-header"><h1 class="fi-header-heading">{{ __('Bexio-Verbindung') }}</h1></header>
@if(session('connection_error'))<p role="alert">{{ session('connection_error') }}</p>@endif
@unless($active)<p role="status">{{ trans('accounting_provider.inactive') }}</p>@endunless
<dl class="cu-admin-facts">
    <div><dt>{{ __('Verbindung') }}</dt><dd><x-filament::badge :color="$connected ? 'success' : 'gray'">{{ $connected ? __('Verbunden') : __('Nicht verbunden') }}</x-filament::badge></dd></div>
    <div><dt>{{ __('Client-Konfiguration') }}</dt><dd><x-filament::badge :color="$ready ? 'success' : 'warning'">{{ $ready ? __('Vorhanden') : __('Unvollständig') }}</x-filament::badge></dd></div>
    <div><dt>{{ __('Callback-Adresse') }}</dt><dd>{{ $callback }}</dd></div>
</dl>
<form method="post" action="{{ route('clubunio.bexio.start') }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="tenant" value="{{ $tenant->slug }}">
<x-filament::button type="submit" icon="heroicon-o-link" :disabled="!$ready || !$active">{{ $connected ? __('Erneut mit Bexio verbinden') : __('Mit Bexio verbinden') }}</x-filament::button>
</form>
@endsection
