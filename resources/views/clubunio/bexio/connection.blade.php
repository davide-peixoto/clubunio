@extends('clubunio.workspace.layout', ['module' => 'bexio'])
@section('content')
<h1>Bexio-Verbindung</h1>
@if(session('connection_error'))<p role="alert">{{ session('connection_error') }}</p>@endif
<dl><dt>Verbindung</dt><dd>{{ $connected ? 'Verbunden' : 'Nicht verbunden' }}</dd><dt>Client-Konfiguration</dt><dd>{{ $ready ? 'Vorhanden' : 'Unvollständig' }}</dd><dt>Callback-Adresse</dt><dd style="overflow-wrap:anywhere">{{ $callback }}</dd></dl>
<form method="post" action="{{ route('clubunio.bexio.start') }}">@csrf @include('clubunio.workspace.context')
<input type="hidden" name="tenant" value="{{ $tenant->slug }}">
<button type="submit" class="primary" @disabled(!$ready)>{{ $connected ? 'Erneut mit Bexio verbinden' : 'Mit Bexio verbinden' }}</button>
</form>
@endsection
