@php($image = in_array(strtolower($mediaType ?? ''), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true))
<div class="cu-pdf-viewer" wire:ignore wire:key="pdf-viewer-{{ hash('sha256', $url.($image ? 'image' : 'pdf')) }}"
    data-clubunio-pdf-viewer data-pdf-url="{{ $url }}" data-media-type="{{ $image ? 'image' : 'pdf' }}"
    data-loading-text="{{ __('Dokument wird geladen…') }}"
    data-render-error-text="{{ __('Dokument konnte nicht angezeigt werden. Bitte erneut öffnen oder herunterladen.') }}"
    data-unavailable-text="{{ __('Dokument ist derzeit nicht verfügbar. Bitte erneut öffnen oder herunterladen.') }}">
    <nav aria-label="{{ __('Dokumentwerkzeuge') }}">
        @if(!$image)
        <button type="button" data-pdf-control="previous" title="{{ __('Vorherige Seite') }}" aria-label="{{ __('Vorherige Seite') }}" disabled>@svg('heroicon-o-chevron-left')</button>
        <label>{{ __('Dokumentseite') }} <input data-pdf-control="page" type="number" min="1" value="1" aria-label="{{ __('Dokumentseite') }}" disabled></label>
        <span data-pdf-control="pages">/ –</span>
        <button type="button" data-pdf-control="next" title="{{ __('Nächste Seite') }}" aria-label="{{ __('Nächste Seite') }}" disabled>@svg('heroicon-o-chevron-right')</button>
        @else
        <span hidden><input data-pdf-control="page" type="number" value="1"><span data-pdf-control="pages"></span><button data-pdf-control="previous" type="button"></button><button data-pdf-control="next" type="button"></button></span>
        @endif
        <button type="button" data-pdf-control="zoom-out" title="{{ __('Verkleinern') }}" aria-label="{{ __('Verkleinern') }}" disabled>@svg('heroicon-o-magnifying-glass-minus')</button>
        <select data-pdf-control="zoom" aria-label="{{ __('Zoom') }}" disabled>
            <option value="fit">{{ __('Seitenbreite') }}</option>
            <option value="height">{{ __('Seitenhöhe') }}</option>
            @foreach([25, 50, 75, 100, 125, 150, 200, 300, 400] as $percent)
            <option value="{{ $percent / 100 }}">{{ $percent }} %</option>
            @endforeach
            <option data-pdf-control="custom-zoom" value="custom" hidden></option>
        </select>
        <button type="button" data-pdf-control="zoom-in" title="{{ __('Vergrössern') }}" aria-label="{{ __('Vergrössern') }}" disabled>@svg('heroicon-o-magnifying-glass-plus')</button>
        <button type="button" data-pdf-control="fit-width" title="{{ __('An Breite anpassen') }}" aria-label="{{ __('An Breite anpassen') }}" aria-pressed="true" disabled>@svg('heroicon-o-arrows-right-left')</button>
        <button type="button" data-pdf-control="fit-height" title="{{ __('An Höhe anpassen') }}" aria-label="{{ __('An Höhe anpassen') }}" aria-pressed="false" disabled>@svg('heroicon-o-arrows-up-down')</button>
        <a href="{{ $url }}{{ str_contains($url, '?') ? '&' : '?' }}download=1" target="_blank" rel="noopener" title="{{ $downloadLabel ?? __('Dokument herunterladen') }}" aria-label="{{ $downloadLabel ?? __('Dokument herunterladen') }}">@svg('heroicon-o-arrow-down-tray')</a>
    </nav>
    <p data-pdf-control="status" role="status" aria-live="polite">{{ __('Dokument wird geladen…') }}</p>
    <div class="cu-pdf-pages" data-pdf-control="document"><canvas data-pdf-control="pdf" aria-label="{{ $name ?? __('PDF-Seite') }}" hidden></canvas></div>
</div>
