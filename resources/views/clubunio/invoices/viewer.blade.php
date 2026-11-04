<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Rechnung PDF') }}</title>
    <link rel="stylesheet" href="{{ asset('clubunio/pdf-viewer.css') }}?v=3">
</head>
<body class="cu-pdf-standalone">
    @include('clubunio.invoices.viewer-content')
    <script type="module" src="{{ asset('clubunio/pdf-viewer.js') }}?v=4"></script>
</body>
</html>
