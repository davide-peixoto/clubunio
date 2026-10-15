<!doctype html>
<html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bexio · ClubUnio</title><link rel="stylesheet" href="{{ asset('css/clubunio-portal.css').'?v=blue-v1' }}">@include('clubunio.brand.head')</head>
<body><header class="portal-header">@include('clubunio.brand.logo')<a href="{{ url('/settings') }}">Einstellungen</a></header><main>
<h1>Bexio-Verbindung</h1>
@if(session('notice'))<p role="status">{{ session('notice') }}</p>@endif
@if(session('connection_error'))<p role="alert">{{ session('connection_error') }}</p>@endif
<dl><dt>Verbindung</dt><dd>{{ $connected ? 'Verbunden' : 'Nicht verbunden' }}</dd><dt>Client-Konfiguration</dt><dd>{{ $ready ? 'Vorhanden' : 'Unvollständig' }}</dd><dt>Callback-Adresse</dt><dd style="overflow-wrap:anywhere">{{ $callback }}</dd></dl>
<form method="post" action="{{ route('clubunio.bexio.start') }}">@csrf
<input type="hidden" name="tenant" value="{{ $tenant->slug }}">
<button type="submit" class="primary" @disabled(!$ready)>{{ $connected ? 'Erneut mit Bexio verbinden' : 'Mit Bexio verbinden' }}</button>
</form>
</main></body></html>
