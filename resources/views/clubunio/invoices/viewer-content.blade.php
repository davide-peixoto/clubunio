@php
    $pdfActor = auth('clubunio')->user();
    $pdfTenant = $tenant ?? request()->route('tenant') ?? \Filament\Facades\Filament::getTenant();
    $canReadPdf = ! $pdfActor || ($pdfTenant instanceof \App\ClubUnio\Models\Tenant
        && \App\ClubUnio\Resources\ProfileUi::profileAllows($pdfActor, $pdfTenant, 'invoices', 'download'));
@endphp
@if($canReadPdf)
<div class="cu-pdf-viewer" wire:ignore wire:key="pdf-viewer-{{ hash('sha256', $url) }}" data-clubunio-pdf-viewer data-pdf-url="{{ $url }}">
    <nav aria-label="PDF-Werkzeuge">
        <button type="button" data-pdf-control="previous" title="Vorherige Seite" aria-label="Vorherige Seite" disabled>@svg('heroicon-o-chevron-left')</button>
        <label>Seite <input data-pdf-control="page" type="number" min="1" value="1" aria-label="Seite" disabled></label>
        <span data-pdf-control="pages">/ –</span>
        <button type="button" data-pdf-control="next" title="Nächste Seite" aria-label="Nächste Seite" disabled>@svg('heroicon-o-chevron-right')</button>
        <select data-pdf-control="zoom" aria-label="Zoom">
            <option value="fit">Seitenbreite</option>
            <option value="0.75">75 %</option>
            <option value="1">100 %</option>
            <option value="1.5">150 %</option>
            <option value="2">200 %</option>
        </select>
        <a href="{{ $url }}{{ str_contains($url, '?') ? '&' : '?' }}download=1" target="_blank" rel="noopener" title="PDF herunterladen" aria-label="PDF herunterladen">@svg('heroicon-o-arrow-down-tray')</a>
    </nav>
    <p data-pdf-control="status" role="status" aria-live="polite">PDF wird geladen…</p>
    <div class="cu-pdf-pages" data-pdf-control="document"><canvas data-pdf-control="pdf" aria-label="PDF-Seite" hidden></canvas></div>
</div>
@endif
