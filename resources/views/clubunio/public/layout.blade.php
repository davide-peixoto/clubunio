<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title') · {{ $tenant->name }}</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-public.css') }}?v=65">
    @include('clubunio.brand.head')
    @yield('head')
</head>
<body>
    <header class="public-header">@include('clubunio.brand.logo')<strong>{{ $tenant->name }}</strong></header>
    <main>@yield('content')</main>
    <footer>{{ __('ClubUnio · Testumgebung') }}</footer>
    @yield('scripts')
</body>
</html>
