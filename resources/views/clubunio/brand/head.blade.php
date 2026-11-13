@if($withFavicon ?? true)
<link rel="icon" type="image/svg+xml" href="{{ asset('brand/blue-v1/clubunio-symbol.svg') }}">
@endif
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('brand/blue-v1/apple-touch-icon.png') }}">
@php($domainBrand = app(\App\ClubUnio\Services\TenantDomainService::class)->brand())
<meta name="theme-color" content="{{ $domainBrand['color'] ?? '#164B8A' }}">
<style>
    .clubunio-brand-lockup { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; }
    .clubunio-attribution { font-size: .75rem; line-height: 1.35; }
    .clubunio-panel-attribution { padding: .75rem 1rem; text-align: center; }
</style>
@if($domainBrand)
<style>
    :root{--clubunio-club-color:{{ $domainBrand['color'] }};--clubunio-club-ink:{{ $domainBrand['ink'] }}}
    .fi-simple-main-ctn{border-top-color:var(--clubunio-club-color)}
    .portal-header strong,a{color:var(--clubunio-club-color)}
    .primary{background:var(--clubunio-club-color);border-color:var(--clubunio-club-color);color:var(--clubunio-club-ink)}
</style>
@endif
<link rel="stylesheet" href="{{ asset('css/clubunio-navigation.css') }}?v=7">
<link rel="stylesheet" href="{{ asset('css/clubunio-administration.css') }}?v=3">
<script src="{{ asset('js/clubunio-navigation.js') }}?v=6" defer></script>
