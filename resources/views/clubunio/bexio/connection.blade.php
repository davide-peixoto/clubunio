@extends('clubunio.workspace.layout', ['module' => 'bexio'])
@section('content')
<h1>{{ __('Bexio-Verbindung') }}</h1>
@if(session('connection_error'))<p role="alert">{{ session('connection_error') }}</p>@endif
<dl><dt>{{ __('Verbindung') }}</dt><dd>{{ $connected ? __('Verbunden') : __('Nicht verbunden') }}</dd><dt>{{ __('Client-Konfiguration') }}</dt><dd>{{ $ready ? __('Vorhanden') : __('Unvollständig') }}</dd><dt>{{ __('Callback-Adresse') }}</dt><dd style="overflow-wrap:anywhere">{{ $callback }}</dd></dl>
<form method="post" action="{{ route('clubunio.bexio.start') }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="tenant" value="{{ $tenant->slug }}">
<button type="submit" class="primary" @disabled(!$ready)>{{ $connected ? __('Erneut mit Bexio verbinden') : __('Mit Bexio verbinden') }}</button>
</form>
@endsection
