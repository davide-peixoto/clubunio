<!doctype html>
<html lang="{{ app()->getLocale() }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ trans('clubhouse.title') }} · {{ $tenant->name }}</title><link rel="stylesheet" href="{{ asset('css/clubunio-clubhouse.css') }}?v=1">@include('clubunio.brand.head')</head>
<body class="cu-ch-public"><main class="cu-clubhouse"><header class="cu-ch-public-header"><span>{{ $tenant->name }}</span><h1>{{ trans('clubhouse.title') }}</h1></header>
    <section class="cu-ch-current"><h2>{{ trans($overview['open_now'] ? 'clubhouse.open_now' : 'clubhouse.closed_now') }}</h2>
        @if($overview['open_until'])<p>{{ trans('clubhouse.open_until', ['time' => \Carbon\CarbonImmutable::parse($overview['open_until'])->format('H:i')]) }}</p>
        @elseif($overview['next_opening'])<p>{{ trans('clubhouse.next_opening', ['time' => \Carbon\CarbonImmutable::parse($overview['next_opening'])->locale(app()->getLocale())->isoFormat('ddd, DD.MM. HH:mm')]) }}</p>@endif
    </section>
    <section class="cu-ch-band"><header><h2>{{ trans('clubhouse.hours') }}</h2></header>@include('clubunio.clubhouse.opening-days', ['days' => array_slice($overview['days'], 0, 14)])</section>
    <section class="cu-ch-band"><header><h2>{{ trans('clubhouse.current_menus') }}</h2></header>@include('clubunio.clubhouse.published-menus', ['publishedMenus' => $overview['menus'], 'publicView' => true])</section>
</main></body></html>
