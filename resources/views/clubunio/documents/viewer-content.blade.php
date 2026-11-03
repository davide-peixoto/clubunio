@php($image = in_array(strtolower($mediaType ?? ''), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true))
<div class="cu-pdf-viewer" wire:ignore wire:key="pdf-viewer-{{ hash('sha256', $url.($image ? 'image' : 'pdf')) }}"
    data-clubunio-pdf-viewer data-pdf-url="{{ $url }}" data-media-type="{{ $image ? 'image' : 'pdf' }}">
    <nav aria-label="Dokumentwerkzeuge">
        @if(!$image)
        <button type="button" data-pdf-control="previous" title="Vorherige Seite" aria-label="Vorherige Seite" disabled>@svg('heroicon-o-chevron-left')</button>
        <label>Seite <input data-pdf-control="page" type="number" min="1" value="1" aria-label="Seite" disabled></label>
        <span data-pdf-control="pages">/ –</span>
        <button type="button" data-pdf-control="next" title="Nächste Seite" aria-label="Nächste Seite" disabled>@svg('heroicon-o-chevron-right')</button>
        @else
        <span hidden><input data-pdf-control="page" type="number" value="1"><span data-pdf-control="pages"></span><button data-pdf-control="previous" type="button"></button><button data-pdf-control="next" type="button"></button></span>
        @endif
        <button type="button" data-pdf-control="zoom-out" title="Verkleinern" aria-label="Verkleinern" disabled>@svg('heroicon-o-magnifying-glass-minus')</button>
        <select data-pdf-control="zoom" aria-label="Zoom" disabled>
            <option value="fit">Seitenbreite</option>
            <option value="height">Seitenhöhe</option>
            @foreach([25, 50, 75, 100, 125, 150, 200, 300, 400] as $percent)
            <option value="{{ $percent / 100 }}">{{ $percent }} %</option>
            @endforeach
            <option data-pdf-control="custom-zoom" value="custom" hidden></option>
        </select>
        <button type="button" data-pdf-control="zoom-in" title="Vergrössern" aria-label="Vergrössern" disabled>@svg('heroicon-o-magnifying-glass-plus')</button>
        <button type="button" data-pdf-control="fit-width" title="An Breite anpassen" aria-label="An Breite anpassen" aria-pressed="true" disabled>@svg('heroicon-o-arrows-right-left')</button>
        <button type="button" data-pdf-control="fit-height" title="An Höhe anpassen" aria-label="An Höhe anpassen" aria-pressed="false" disabled>@svg('heroicon-o-arrows-up-down')</button>
        <a href="{{ $url }}{{ str_contains($url, '?') ? '&' : '?' }}download=1" target="_blank" rel="noopener" title="{{ $downloadLabel ?? 'Dokument herunterladen' }}" aria-label="{{ $downloadLabel ?? 'Dokument herunterladen' }}">@svg('heroicon-o-arrow-down-tray')</a>
    </nav>
    <p data-pdf-control="status" role="status" aria-live="polite">Dokument wird geladen…</p>
    <div class="cu-pdf-pages" data-pdf-control="document"><canvas data-pdf-control="pdf" aria-label="{{ $name ?? 'PDF-Seite' }}" hidden></canvas></div>
</div>
