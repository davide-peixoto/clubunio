<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnung PDF</title>
    <link rel="stylesheet" href="{{ asset('clubunio/pdf-viewer.css') }}?v=2">
</head>
<body class="cu-pdf-standalone">
    @include('clubunio.invoices.viewer-content')
    <script type="module" src="{{ asset('clubunio/pdf-viewer.js') }}?v=2"></script>
</body>
</html>
