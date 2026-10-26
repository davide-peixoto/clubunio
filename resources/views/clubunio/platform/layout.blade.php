<!doctype html>
<html lang="de-CH"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="same-origin">
<title>Plattformverwaltung · ClubUnio</title><link rel="icon" href="{{ asset('brand/blue-v1/clubunio-symbol.svg') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}"><link rel="stylesheet" href="{{ asset('css/clubunio-platform.css') }}"></head>
<body><header class="platform-header"><a href="{{ route('clubunio.platform.index') }}"><img src="{{ asset('brand/blue-v1/clubunio-logo-horizontal.svg') }}" alt="ClubUnio" width="180" height="40"></a>
<nav aria-label="Plattform">@isset($actor)<a href="{{ route('clubunio.platform.index') }}">Vereine</a><a href="{{ url('/') }}">Vereinsverwaltung</a>@endisset</nav>
@isset($actor)<span>{{ $actor->display_name }}</span><form method="post" action="{{ route('clubunio.platform.logout') }}">@csrf<button class="icon-button" title="Abmelden" aria-label="Abmelden">@svg('heroicon-o-arrow-right-on-rectangle')</button></form>@endisset</header>
<main class="platform-main">
@if(session('notice'))<div class="notice" role="status">{{ session('notice') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main></body></html>
