@assets
    <link rel="stylesheet" href="{{ asset('clubunio/pdf-viewer.css') }}?v=2">
    <script type="module" src="{{ asset('clubunio/pdf-viewer.js') }}?v=2"></script>
@endassets
@include('clubunio.invoices.viewer-content')
