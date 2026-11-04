@include('clubunio.documents.viewer-assets')
<div class="space-y-4">
    @forelse($documents as $document)
        <div x-data="{ previewOpen: false }">
            <strong>{{ $document->kind === 'portrait' ? __('Passfoto') : __('Ausweis') }}</strong>
            <p>{{ $document->original_filename ?: $document->filename }}</p>
            <a href="{{ route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id, 'download' => 1]) }}" title="{{ __('Herunterladen') }}" aria-label="{{ __('Herunterladen') }}" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px">@svg('heroicon-o-arrow-down-tray', '', ['width' => 20, 'height' => 20])</a>
            <button type="button" x-on:click="previewOpen = !previewOpen" x-bind:aria-expanded="previewOpen" aria-label="{{ __('Vorschau') }}" title="{{ __('Vorschau') }}" style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px">@svg('heroicon-o-eye', '', ['width' => 20, 'height' => 20])</button>
            <template x-if="previewOpen">
                <div>
                    @include('clubunio.documents.viewer-content', [
                        'url' => route('clubunio.registrations.document', ['tenant' => $tenant->slug, 'document' => $document->id]),
                        'name' => $document->kind === 'portrait' ? __('Passfoto') : __('Ausweis'),
                        'mediaType' => ($document->content_type ?? null) ?: (in_array(strtolower(pathinfo($document->filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'image/jpeg' : 'application/pdf'),
                    ])
                </div>
            </template>
        </div>
    @empty
        <p>{{ __('Keine Dokumente vorhanden.') }}</p>
    @endforelse
</div>
