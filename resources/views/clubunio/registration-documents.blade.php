<div class="space-y-4">
    @forelse($documents as $document)
        <div>
            <strong>{{ $document->kind === 'portrait' ? 'Passfoto' : 'Ausweis' }}</strong>
            <p>{{ $document->original_filename ?: $document->filename }}</p>
            <a href="{{ route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id, 'download' => 1]) }}" title="Herunterladen" aria-label="Herunterladen">@svg('heroicon-o-arrow-down-tray', 'h-5 w-5')</a>
            <iframe title="{{ $document->kind === 'portrait' ? 'Passfoto' : 'Ausweis' }}" src="{{ route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id]) }}" style="width:100%;height:420px;border:1px solid #d1d5db"></iframe>
        </div>
    @empty
        <p>Keine Dokumente vorhanden.</p>
    @endforelse
</div>
