<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $portalTitle ?? __('ClubUnio · Vereinsportal') }}</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-portal.css').'?v=blue-v1' }}">
    @isset($portalModule)
        <link rel="stylesheet" href="{{ asset('css/clubunio-planning.css').'?v=blue-v1' }}">
    @endisset
    @isset($tenant)
        <link rel="stylesheet" href="{{ asset('css/clubunio-portal-shell.css').'?v=3' }}">
        <script src="{{ asset('js/clubunio-portal-shell.js').'?v=1' }}" defer></script>
    @endisset
    @stack('head')
@include('clubunio.brand.head')</head>
<body>
    @isset($tenant)
        @include('clubunio.portal.shell')
    @else
        <header class="portal-header">@include('clubunio.brand.logo')</header>
        <main>@yield('content')</main>
    @endisset
    @if(config('clubunio-support.enabled') && !isset($portalModule))
        <script src="{{ asset('js/clubunio-help-widget.js').'?v=3' }}"
            data-close-label="{{ trans('support.close_panel') }}"
            data-support-url="{{ isset($tenant) ? route('clubunio.support.center', ['club' => $tenant->id]) : url('/help') }}"
            data-support-label="{{ trans('support.title') }}"></script>
    @endif
</body>
</html>
