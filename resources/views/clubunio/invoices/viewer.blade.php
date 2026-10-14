<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnung PDF</title>
    <link rel="stylesheet" href="{{ asset('clubunio/pdf-viewer.css') }}">
</head>
<body data-pdf-url="{{ $url }}">
    <nav aria-label="PDF-Werkzeuge">
        <button id="previous" title="Vorherige Seite" aria-label="Vorherige Seite" disabled>@svg('heroicon-o-chevron-left')</button>
        <label>Seite <input id="page" type="number" min="1" value="1" aria-label="Seite" disabled></label>
        <span id="pages">/ –</span>
        <button id="next" title="Nächste Seite" aria-label="Nächste Seite" disabled>@svg('heroicon-o-chevron-right')</button>
        <select id="zoom" aria-label="Zoom">
            <option value="fit">Seitenbreite</option>
            <option value="0.75">75 %</option>
            <option value="1">100 %</option>
            <option value="1.5">150 %</option>
            <option value="2">200 %</option>
        </select>
        <a href="{{ $url }}?download=1" title="PDF herunterladen" aria-label="PDF herunterladen">@svg('heroicon-o-arrow-down-tray')</a>
    </nav>
    <p id="status" role="status" aria-live="polite">PDF wird geladen…</p>
    <main id="document"><canvas id="pdf" aria-label="PDF-Seite" hidden></canvas></main>
    <script type="module" src="{{ asset('clubunio/pdf-viewer.js') }}"></script>
</body>
</html>
