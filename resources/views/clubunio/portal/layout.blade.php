<!doctype html>
<html lang="de-CH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ClubUnio · Vereinsportal</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
</head>
<body>
    <header class="portal-header">
        <strong>ClubUnio</strong>
        @isset($tenant)
            <nav aria-label="Verein">
                @foreach($tenants as $availableTenant)
                    <a href="{{ route('clubunio.portal.members', ['tenant' => $availableTenant->slug]) }}" @if($availableTenant->id === $tenant->id) aria-current="page" @endif>{{ $availableTenant->name }}</a>
                @endforeach
            </nav>
            <a class="icon-button" href="{{ route('clubunio.portal.notifications', ['tenant' => $tenant->slug]) }}" title="Benachrichtigungen" aria-label="Benachrichtigungen">@svg('heroicon-o-bell')</a>
            <a href="{{ route('clubunio.portal.expenses', ['tenant'=>$tenant->slug]) }}">Spesen</a>
            <a href="{{ route('clubunio.portal.invoices', ['tenant'=>$tenant->slug]) }}">Rechnungen</a>
            <a href="{{ route('clubunio.portal.banks', ['tenant'=>$tenant->slug]) }}">Bankkonten</a>
            <a href="{{ route('clubunio.planning.index', $tenant->slug) }}">Teamplanung</a>
            <a href="{{ route('clubunio.bus.index', $tenant->slug) }}">Bus</a>
            @if(in_array(app(\App\ClubUnio\Services\MemberAccessService::class)->membership(auth('portal')->user(), $tenant)?->role, ['admin','office','technical_lead'], true))
                <a href="{{ route('clubunio.portal.registrations', ['tenant' => $tenant->slug]) }}">Anmeldungen</a>
            @endif
            <form method="post" action="{{ route('clubunio.portal.logout') }}">@csrf
                <button class="icon-button" type="submit" title="Abmelden" aria-label="Abmelden">@svg('heroicon-o-arrow-right-on-rectangle')</button>
            </form>
        @endisset
    </header>
    <main>@yield('content')</main>
</body>
</html>
