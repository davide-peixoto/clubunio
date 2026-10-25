<x-filament-panels::page>
    @php
        $can = fn($op) => \App\ClubUnio\Resources\ProfileUi::allows('documents', $op);
    @endphp
    <style>
        .ds-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.ds-field{display:flex;flex-direction:column;gap:.35rem;min-width:0}.ds-field input,.ds-field select,.ds-field textarea,.ds-search{width:100%;border:1px solid #9ca3af;border-radius:4px;padding:.45rem;background:transparent;color:inherit}.ds-tools{display:flex;flex-wrap:wrap;align-items:center;gap:.65rem}.ds-table{width:100%;border-collapse:collapse;font-size:.875rem}.ds-table th,.ds-table td{text-align:left;padding:.6rem;border-bottom:1px solid #9ca3af55;vertical-align:top;overflow-wrap:anywhere}.ds-table input{min-width:5rem;max-width:100%;border:1px solid #9ca3af;border-radius:4px;padding:.35rem;background:transparent}.ds-scroll{overflow:auto;max-height:25rem}.ds-band{border-top:1px solid #9ca3af55;padding-top:1.2rem;margin-top:1rem}.ds-options{max-height:15rem;overflow:auto;display:grid;gap:.5rem;padding:.5rem 0}.ds-heading{font-size:1.1rem;font-weight:600;margin-bottom:.8rem}.ds-preview{width:100%;height:650px;border:1px solid #9ca3af55;background:white}.ds-error{color:#dc2626;font-size:.875rem}.ds-check{display:flex;gap:.5rem;align-items:center}@media(max-width:767px){.ds-grid{grid-template-columns:1fr}.ds-preview{height:520px}}
    </style>
    @if($errors->any())<div class="ds-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="ds-tools">
        @if($can('create'))<x-filament::button wire:click="defaults" icon="heroicon-o-document-duplicate" color="gray">Standardvorlagen</x-filament::button><x-filament::button wire:click="editTemplate(true)" icon="heroicon-o-plus" color="gray">Neue Vorlage</x-filament::button>@endif
        @if($can('update') && $templateId)<x-filament::button wire:click="editTemplate" icon="heroicon-o-pencil-square" color="gray">Neue Version</x-filament::button>@endif
    </div>
    @if($editing)
    <section class="ds-band">
        <h2 class="ds-heading">Vorlage {{ $previousTemplate ? ' / neue Version' : '' }}</h2>
        <div class="ds-grid">
            <label class="ds-field">Name<input wire:model="editor.name" maxlength="160"></label>
            <label class="ds-field">Titel<input wire:model="editor.title" maxlength="160"></label>
            <label class="ds-field">Dokumenttyp<select wire:model="editor.type_id"><option value="">Auswählen</option>@foreach($lookups['types'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label>
            <label class="ds-check"><input type="checkbox" wire:model="editor.signature_required">Unterschrift erforderlich</label>
        </div>
        <label class="ds-field" style="margin-top:1rem">Bedingungen<textarea wire:model="editor.text" rows="10" maxlength="15000"></textarea></label>
        <div class="ds-scroll"><table class="ds-table"><thead><tr><th>Artikel / Marke</th><th>Artikelnummer</th><th>Menge</th><th>Preis (Rappen)</th><th>Grösse</th><th>Initialen</th><th></th></tr></thead><tbody>
        @foreach($editor['items'] as $i => $item)
        <tr wire:key="ds-item-{{ $i }}"><td><input aria-label="Artikel" wire:model="editor.items.{{ $i }}.description"><input aria-label="Marke" wire:model="editor.items.{{ $i }}.brand"></td><td><input aria-label="Artikelnummer" wire:model="editor.items.{{ $i }}.reference"></td><td><input type="number" min="1" max="999" aria-label="Menge" wire:model="editor.items.{{ $i }}.quantity"></td><td><input type="number" min="0" max="100000000" aria-label="Preis in Rappen" wire:model="editor.items.{{ $i }}.price_cents"></td><td><input aria-label="Grösse" wire:model="editor.items.{{ $i }}.size"></td><td><input aria-label="Initialen" wire:model="editor.items.{{ $i }}.initials"></td><td><x-filament::icon-button wire:click="removeItem({{ $i }})" icon="heroicon-o-trash" label="Position entfernen" color="danger" /></td></tr>
        @endforeach
        </tbody></table></div>
        <div class="ds-tools" style="margin-top:1rem"><x-filament::icon-button wire:click="addItem" icon="heroicon-o-plus" label="Position hinzufügen" /><x-filament::button wire:click="saveTemplate" icon="heroicon-o-check">Version speichern</x-filament::button><x-filament::button wire:click="$set('editing', false)" color="gray">Abbrechen</x-filament::button></div>
        <div style="margin-top:1rem;font-size:.8rem;overflow-wrap:anywhere">@foreach(\App\ClubUnio\Services\DocumentSeriesRenderer::FIELDS as $field)<code>&#123;&#123;{{ $field }}&#125;&#125;</code> @endforeach</div>
    </section>
    @endif
    <section class="ds-band">
        <h2 class="ds-heading">Neue Serie</h2>
        <div class="ds-grid">
            <div>
                <label class="ds-field">Vorlage<select wire:model.live="templateId"><option value="">Auswählen</option>@foreach($lookups['templates'] as $template)<option value="{{ $template->id }}">{{ $template->name }} / v{{ $template->version }}</option>@endforeach</select></label>
                <label class="ds-field" style="margin-top:1rem">Saison / Mannschaft<input type="search" wire:model.live.debounce.300ms="teamSearch" placeholder="Suchen"></label>
                <div class="ds-options">@foreach($lookups['teams'] as $team)<label class="ds-check"><input type="checkbox" value="{{ $team->id }}" wire:model.live="teams">{{ $team->team->name }} / {{ $team->season->name }}</label>@endforeach</div>
            </div>
            <div class="ds-field">
                <label class="ds-field">Zielgruppe<select wire:model.live="target"><option value="players">Spieler</option><option value="staff">Staff</option><option value="both">Spieler und Staff</option></select></label>
                <label class="ds-field">Datum<input type="date" wire:model.live="date"></label>
                <label class="ds-check"><input type="checkbox" wire:model.live="signatureRequired">Unterschrift erforderlich</label>
                <label class="ds-check"><input type="checkbox" wire:model.live="portalVisible">Im Mitgliederportal freigeben</label>
                <label class="ds-field">Verantwortlicher<input wire:model.live.debounce.500ms="common.responsible" maxlength="160"></label>
                <label class="ds-field">Schlüssel Grundnummer<input wire:model.live.debounce.500ms="common.base" maxlength="160"></label>
            </div>
        </div>
        <div class="ds-tools" style="margin-top:1rem"><x-filament::button wire:click="checkPreview" icon="heroicon-o-eye" color="gray" wire:loading.attr="disabled">Vorschau prüfen</x-filament::button></div>
    </section>
    @if($filteredCandidates->isNotEmpty())
    <section class="ds-band">
        <h2 class="ds-heading">Empfänger</h2>
        <input class="ds-search" type="search" aria-label="Empfänger suchen" placeholder="Empfänger suchen" wire:model.live.debounce.300ms="recipientSearch">
        <div class="ds-scroll"><table class="ds-table"><thead><tr><th></th><th>Mitglied / Mannschaft</th><th>Schlüsselnummer</th><th>Grundnummer</th><th>Verantwortlicher</th></tr></thead><tbody>
        @foreach($filteredCandidates as $person)<tr wire:key="ds-person-{{ $person['id'] }}"><td><input type="checkbox" aria-label="{{ $person['name'] }} auswählen" wire:model.live="selected.{{ $person['id'] }}"></td><td>{{ $person['name'] }}<br><small>{{ $person['teams'] }}</small></td>@foreach(['number'=>'Schlüsselnummer','base'=>'Grundnummer','responsible'=>'Verantwortlicher'] as $key=>$label)<td><input aria-label="{{ $label }} für {{ $person['name'] }}" maxlength="160" wire:model.live.debounce.500ms="individual.{{ $person['id'] }}.{{ $key }}"></td>@endforeach</tr>@endforeach
        </tbody></table></div>
    </section>
    @endif
    @if($review)
    <section class="ds-band">
        <h2 class="ds-heading">Vorschau / {{ count($review['snapshot']['recipients']) }} Empfänger</h2>
        @foreach($review['snapshot']['recipients'] as $person)@if(isset($person['error']))<p class="ds-error">{{ $person['member_name'] }}: {{ $person['error'] }}</p>@endif @endforeach
        @if($previewHtml)<iframe title="Dokumentvorschau" class="ds-preview" sandbox srcdoc="{{ $previewHtml }}"></iframe>@endif
        @if($review['can_create'] && $can('create') && $can('upload') && $can('send'))
        <div class="ds-tools" style="margin-top:1rem"><label class="ds-check"><input type="checkbox" wire:model.live="acknowledge">Empfänger, Inhalt und Freigabe geprüft</label><x-filament::button wire:click="createSeries" wire:loading.attr="disabled" :disabled="!$acknowledge" icon="heroicon-o-check">Serie erstellen</x-filament::button></div>
        @endif
    </section>
    @endif
    <section class="ds-band">
        <h2 class="ds-heading">Letzte Serien</h2>
        <input class="ds-search" type="search" aria-label="Serien suchen" placeholder="Vorlage suchen" wire:model.live.debounce.300ms="historySearch">
        <table class="ds-table"><thead><tr><th>Serie</th><th>Erstellt</th><th>Dokumente</th><th></th></tr></thead><tbody>@forelse($history as $row)<tr><td>#{{ $row->id }}</td><td>{{ $row->created_at->format('d.m.Y H:i') }}</td><td>{{ $row->completed_count }} / {{ $row->recipients_count }}</td><td><x-filament::icon-button icon="heroicon-o-folder-open" label="Serie öffnen" wire:click="openSeries({{ $row->id }})" /></td></tr>@empty<tr><td colspan="4">Keine Serien</td></tr>@endforelse</tbody></table>
        <div class="ds-tools"><x-filament::icon-button icon="heroicon-o-chevron-left" label="Vorherige Seite" wire:click="historyMove(-1)" :disabled="$historyPage <= 1" /><span>{{ $history->currentPage() }} / {{ $history->lastPage() }}</span><x-filament::icon-button icon="heroicon-o-chevron-right" label="Nächste Seite" wire:click="historyMove(1)" :disabled="!$history->hasMorePages()" /></div>
    </section>
    @if($seriesId)
    <section class="ds-band">
        <div class="ds-tools"><h2 class="ds-heading">Serie #{{ $seriesId }}</h2>@if($can('create') && $can('upload') && $can('send') && $recipients->contains(fn($r) => !$r->document_id))<x-filament::button wire:click="processNext" icon="heroicon-o-play" wire:loading.attr="disabled">Nächstes Dokument</x-filament::button>@endif</div>
        <div class="ds-scroll"><table class="ds-table"><thead><tr><th>Mitglied</th><th>Status / Nachweis</th><th>Dokumente / Rückgabe</th></tr></thead><tbody>
        @foreach($recipients as $row)
        <tr wire:key="ds-result-{{ $row->id }}"><td>{{ $row->snapshot['member_name'] }}</td><td>
        @php
            $statusLabel = match(true) {
                (bool) $row->signed_document_id => 'Geprüfte PDF-Rückgabe',
                (bool) $row->signed_upload_id => 'Rückgabe: Übertragung offen',
                $row->receipt?->state === 'confirmed' => 'Digital bestätigt',
                (bool) $row->document_id => $row->snapshot['definition']['signature_required'] ? 'Unterschrift offen' : 'Abgelegt',
                (bool) $row->upload_id => 'Original: Übertragung/Abschluss offen',
                default => 'Ausstehend',
            };
            $statusKey = $row->signed_document_id || $row->receipt?->state === 'confirmed' ? 'akzeptiert'
                : ($row->last_error || $row->signed_upload_id ? 'in prüfung' : ($row->document_id && !$row->snapshot['definition']['signature_required'] ? 'freigegeben' : 'offen'));
        @endphp
        <x-filament::badge :color="\App\ClubUnio\Services\StatusAppearance::palette($statusKey)">{{ $statusLabel }}</x-filament::badge>
        @if($row->signed_document_id)<small>Upload/Prüfung: Konto #{{ $row->checked_by }}, {{ $row->checked_at?->format('d.m.Y H:i') }}</small>@endif
        @if($row->receipt?->state === 'confirmed')<br><small>Digital bestätigt: {{ $row->receipt->confirmation_name }} / {{ $row->receipt->confirmation_kind === 'parent' ? 'Elternvertretung' : 'Mitglied' }}</small>@endif
        @if($row->document_id && !$row->snapshot['portal_visible'])<br><small>Nur privates Dossier</small>@endif
        @if($row->last_error)<br><span class="ds-error">Wiederholung erforderlich</span>@endif
        </td><td><div class="ds-tools">
        @if($can('download') && $row->document_id && \Illuminate\Support\Facades\Route::has('clubunio.document-series.original'))<x-filament::icon-button tag="a" :href="route('clubunio.document-series.original', [\Filament\Facades\Filament::getTenant()->slug, $seriesId, $row->id])" target="_blank" icon="heroicon-o-document-arrow-down" label="Original-PDF" />@endif
        @if($can('download') && $row->signed_document_id && \Illuminate\Support\Facades\Route::has('clubunio.document-series.signed'))<x-filament::icon-button tag="a" :href="route('clubunio.document-series.signed', [\Filament\Facades\Filament::getTenant()->slug, $seriesId, $row->id])" target="_blank" icon="heroicon-o-document-check" label="Geprüfte PDF-Rückgabe" />@endif
        @if($can('upload') && $can('update') && $row->document_id && $row->snapshot['definition']['signature_required'] && !$row->signed_upload_id)<x-filament::icon-button wire:click="chooseReturn({{ $row->id }})" icon="heroicon-o-arrow-up-tray" label="Geprüfte Rückgabe hochladen" />@endif
        @if($can('upload') && $can('update') && $row->signed_upload_id && !$row->signed_document_id)<x-filament::icon-button wire:click="retryReturn({{ $row->id }})" icon="heroicon-o-arrow-path" label="Rückgabe fortsetzen" />@endif
        </div></td></tr>
        @endforeach
        </tbody></table></div>
        @if($returnRecipient)<form wire:submit="uploadReturn" class="ds-field" style="margin-top:1rem"><h3 class="ds-heading">Geprüfte Rückgabe #{{ $returnRecipient }}</h3><input type="file" accept="application/pdf" wire:model="signedFile" aria-label="Unterschriebene PDF-Rückgabe"><label class="ds-check"><input type="checkbox" wire:model="returnChecked">Rückgabe und Unterschrift manuell dem Original zugeordnet und geprüft</label><div><x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray">Rückgabe speichern</x-filament::button></div></form>@endif
    </section>
    @endif
</x-filament-panels::page>
