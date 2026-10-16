<div class="space-y-4">
    @forelse($documents as $document)
        <div x-data="{ previewOpen: false }">
            <strong>{{ $document->kind === 'portrait' ? 'Passfoto' : 'Ausweis' }}</strong>
            <p>{{ $document->original_filename ?: $document->filename }}</p>
            <a href="{{ route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id, 'download' => 1]) }}" title="Herunterladen" aria-label="Herunterladen" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px">@svg('heroicon-o-arrow-down-tray', '', ['width' => 20, 'height' => 20])</a>
            <button type="button" x-on:click="previewOpen = !previewOpen" x-bind:aria-expanded="previewOpen" aria-label="Vorschau" title="Vorschau" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px">@svg('heroicon-o-eye', '', ['width' => 20, 'height' => 20])</button>
            <template x-if="previewOpen">
                @if(in_array(strtolower(pathinfo($document->filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true))
                    <img alt="{{ $document->kind === 'portrait' ? 'Passfoto' : 'Ausweis' }}" src="{{ route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id]) }}" style="display:block;width:100%;max-height:420px;object-fit:contain;border:1px solid #d1d5db">
                @else
                <iframe title="{{ $document->kind === 'portrait' ? 'Passfoto' : 'Ausweis' }}" src="{{ route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id]) }}" style="width:100%;height:420px;border:1px solid #d1d5db"></iframe>
                @endif
            </template>
        </div>
    @empty
        <p>Keine Dokumente vorhanden.</p>
    @endforelse
</div>
