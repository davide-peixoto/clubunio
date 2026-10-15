<!doctype html>
<html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bexio | ClubUnio</title><link rel="stylesheet" href="{{ asset('css/clubunio-portal.css').'?v=blue-v1' }}">@include('clubunio.brand.head')</head>
<body><main><h1>Bexio-Verbindung</h1>
<form method="get" action="{{ route('clubunio.bexio.show') }}">
<label for="tenant">Verein</label><select id="tenant" name="tenant" required>
@foreach($tenants as $tenant)<option value="{{ $tenant->slug }}">{{ $tenant->name }}</option>@endforeach
</select><button type="submit">Weiter</button>
</form></main></body></html>
