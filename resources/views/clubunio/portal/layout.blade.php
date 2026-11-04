<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('ClubUnio · Vereinsportal') }}</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-portal.css').'?v=blue-v1' }}">
@include('clubunio.brand.head')</head>
<body>
    <header class="portal-header">
        @include('clubunio.brand.logo')
        @isset($tenant)
            <nav aria-label="{{ __('Verein') }}">
                @foreach($tenants as $availableTenant)
                    <a href="{{ route('clubunio.portal.members', ['tenant' => $availableTenant->slug]) }}" @if($availableTenant->id === $tenant->id) aria-current="page" @endif>{{ $availableTenant->name }}</a>
                @endforeach
            </nav>
            <a class="icon-button" href="{{ route('clubunio.portal.notifications', ['tenant' => $tenant->slug]) }}" title="{{ __('Benachrichtigungen') }}" aria-label="{{ __('Benachrichtigungen') }}">@svg('heroicon-o-bell')</a>
            @if(\App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'expenses', 'view'))<a href="{{ route('clubunio.portal.expenses', ['tenant'=>$tenant->slug]) }}">{{ __('Spesen') }}</a>@endif
            @if(\App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'invoices', 'view'))<a href="{{ route('clubunio.portal.invoices', ['tenant'=>$tenant->slug]) }}">{{ __('Rechnungen') }}</a>@endif
            <a href="{{ route('clubunio.portal.announcements', $tenant->slug) }}">{{ __('Ankündigungen') }}</a>
            <a href="{{ route('clubunio.portal.sponsor-runs', $tenant->slug) }}">{{ __('Sponsorenlauf') }}</a>
            @if(app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter(auth('portal')->user(), $tenant) && \App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'documents', 'view'))
                <a href="{{ route('clubunio.portal.material.index', $tenant->slug) }}">{{ __('Material') }}</a>
            @endif
            <a href="{{ route('clubunio.portal.banks', ['tenant'=>$tenant->slug]) }}">{{ __('Bankkonten') }}</a>
            <a href="{{ route('clubunio.planning.index', $tenant->slug) }}">{{ __('Teamplanung') }}</a>
            <a href="{{ route('clubunio.bus.index', $tenant->slug) }}">{{ __('Bus') }}</a>
            @if(in_array(app(\App\ClubUnio\Services\MemberAccessService::class)->membership(auth('portal')->user(), $tenant)?->role, ['admin','office','technical_lead'], true))
                <a href="{{ route('clubunio.portal.registrations', ['tenant' => $tenant->slug]) }}">{{ __('Anmeldungen') }}</a>
            @endif
            <form method="post" action="{{ route('clubunio.portal.logout') }}">@csrf
                <button class="icon-button" type="submit" title="{{ __('Abmelden') }}" aria-label="{{ __('Abmelden') }}">@svg('heroicon-o-arrow-right-on-rectangle')</button>
            </form>
        @endisset
    </header>
    <main>@yield('content')</main>
</body>
</html>
