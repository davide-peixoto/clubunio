<!doctype html>
<html lang="de-CH">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="300">
    <title>Info-Display · {{ $brand['title'] }}</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-signage.css') }}?v=66">
    @include('clubunio.brand.head')
    @livewireStyles
    <script src="{{ asset('js/clubunio-signage.js') }}?v=66" defer></script>
</head>
<body class="sg-kiosk">
    @include('clubunio.signage-board', ['publicDisplay'=>true])
    @livewireScripts
</body>
</html>
