<x-filament-panels::page>
    @php
        $can = fn($op) => \App\ClubUnio\Resources\ProfileUi::allows('documents', $op);
    @endphp
    <style>
        .ds-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.ds-field{display:flex;flex-direction:column;gap:.35rem;min-width:0}.ds-field textarea{width:100%;border:1px solid #9ca3af;border-radius:4px;padding:.45rem;background:transparent;color:inherit}.ds-tools{display:flex;flex-wrap:wrap;align-items:center;gap:.65rem}.ds-table th,.ds-table td{overflow-wrap:anywhere}.ds-table .fi-input-wrp{min-width:5rem}.ds-scroll{overflow:auto;max-height:25rem}.ds-options{max-height:15rem;overflow:auto;display:grid;gap:.5rem;padding:.5rem 0}.ds-heading{font-size:1.1rem;font-weight:600;margin-bottom:.8rem}.ds-preview{width:100%;height:650px;border:1px solid #9ca3af55;background:white}.ds-error{color:#dc2626;font-size:.875rem}.ds-check{display:flex;gap:.5rem;align-items:center}@media(max-width:767px){.ds-grid{grid-template-columns:1fr}.ds-preview{height:520px}}
    </style>
    <style>
        .ds-field input[type=checkbox],.ds-table input[type=checkbox]{width:1rem;height:1rem;min-width:1rem;flex:0 0 1rem;padding:0}
        .ds-conditions{margin:16px 0}.ds-conditions textarea.fi-input{padding:8px 12px;resize:vertical}
        .ds-check{justify-content:flex-start;overflow-wrap:anywhere}
        .ds-scroll{min-width:0;max-width:100%}
        .ds-table .fi-badge-label{white-space:normal;overflow:visible;text-overflow:clip}
        @media(max-width:767px){.ds-scroll .ds-table{min-width:40rem}}
    </style>
    @if($errors->any())<div class="ds-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <div class="ds-tools">
        @if($can('create'))<x-filament::button wire:click="defaults" icon="heroicon-o-document-duplicate" color="gray">{{ __('Standardvorlagen') }}</x-filament::button><x-filament::button wire:click="editTemplate(true)" icon="heroicon-o-plus" color="gray">{{ __('Neue Vorlage') }}</x-filament::button>@endif
        @if($can('update') && $templateId)<x-filament::button wire:click="editTemplate" icon="heroicon-o-pencil-square" color="gray">{{ __('Neue Version') }}</x-filament::button>@endif
    </div>
    @if($editing)
    <x-filament::section>
        <x-slot name="heading">{{ __('Vorlage') }} {{ $previousTemplate ? __(' / neue Version') : '' }}</x-slot>
        <div class="ds-grid">
            <label class="ds-field">{{ __('Name') }}<x-filament::input.wrapper><x-filament::input wire:model="editor.name" maxlength="160" /></x-filament::input.wrapper></label>
            <label class="ds-field">{{ __('Titel') }}<x-filament::input.wrapper><x-filament::input wire:model="editor.title" maxlength="160" /></x-filament::input.wrapper></label>
            <label class="ds-field">{{ __('Dokumenttyp') }}<x-filament::input.wrapper><x-filament::input.select wire:model="editor.type_id"><option value="">{{ __('Auswählen') }}</option>@foreach($lookups['types'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label class="ds-check"><x-filament::input.checkbox wire:model="editor.signature_required" />{{ __('Unterschrift erforderlich') }}</label>
        </div>
        <label class="cu-admin-field ds-conditions">{{ __('Bedingungen') }}<x-filament::input.wrapper><textarea class="fi-input" wire:model="editor.text" rows="10" maxlength="15000"></textarea></x-filament::input.wrapper></label>
        <div class="cu-admin-journal ds-scroll"><table class="ds-table"><thead><tr><th>{{ __('Artikel / Marke') }}</th><th>{{ __('Artikelnummer') }}</th><th>{{ __('Menge') }}</th><th>{{ __('Preis (Rappen)') }}</th><th>{{ __('Grösse') }}</th><th>{{ __('Initialen') }}</th><th></th></tr></thead><tbody>
        @foreach($editor['items'] as $i => $item)
        <tr wire:key="ds-item-{{ $i }}"><td><x-filament::input.wrapper><x-filament::input aria-label="{{ __('Artikel') }}" wire:model="editor.items.{{ $i }}.description" /></x-filament::input.wrapper><x-filament::input.wrapper><x-filament::input aria-label="{{ __('Marke') }}" wire:model="editor.items.{{ $i }}.brand" /></x-filament::input.wrapper></td><td><x-filament::input.wrapper><x-filament::input aria-label="{{ __('Artikelnummer') }}" wire:model="editor.items.{{ $i }}.reference" /></x-filament::input.wrapper></td><td><x-filament::input.wrapper><x-filament::input type="number" min="1" max="999" aria-label="{{ __('Menge') }}" wire:model="editor.items.{{ $i }}.quantity" /></x-filament::input.wrapper></td><td><x-filament::input.wrapper><x-filament::input type="number" min="0" max="100000000" aria-label="{{ __('Preis in Rappen') }}" wire:model="editor.items.{{ $i }}.price_cents" /></x-filament::input.wrapper></td><td><x-filament::input.wrapper><x-filament::input aria-label="{{ __('Grösse') }}" wire:model="editor.items.{{ $i }}.size" /></x-filament::input.wrapper></td><td><x-filament::input.wrapper><x-filament::input aria-label="{{ __('Initialen') }}" wire:model="editor.items.{{ $i }}.initials" /></x-filament::input.wrapper></td><td><x-filament::icon-button wire:click="removeItem({{ $i }})" icon="heroicon-o-trash" label="{{ __('Position entfernen') }}" tooltip="{{ __('Position entfernen') }}" color="danger" /></td></tr>
        @endforeach
        </tbody></table></div>
        <div class="ds-tools" style="margin-top:1rem"><x-filament::icon-button wire:click="addItem" icon="heroicon-o-plus" label="{{ __('Position hinzufügen') }}" tooltip="{{ __('Position hinzufügen') }}" /><x-filament::button wire:click="saveTemplate" icon="heroicon-o-check">{{ __('Version speichern') }}</x-filament::button><x-filament::button wire:click="$set('editing', false)" color="gray">{{ __('Abbrechen') }}</x-filament::button></div>
        <div style="margin-top:1rem;font-size:.8rem;overflow-wrap:anywhere">@foreach(\App\ClubUnio\Services\DocumentSeriesRenderer::FIELDS as $field)<code>&#123;&#123;{{ $field }}&#125;&#125;</code> @endforeach</div>
    </x-filament::section>
    @endif
    <x-filament::section>
        <x-slot name="heading">{{ __('Neue Serie') }}</x-slot>
        <div class="ds-grid">
            <div>
                <label class="ds-field">{{ __('Vorlage') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="templateId"><option value="">{{ __('Auswählen') }}</option>@foreach($lookups['templates'] as $template)<option value="{{ $template->id }}">{{ $template->name }} {{ __('/ v') }}{{ $template->version }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <div class="ds-field" style="margin-top:1rem"><label for="ds-team-search">{{ __('Saison / Mannschaft') }}</label><div class="ds-tools"><x-filament::input.wrapper><x-filament::input id="ds-team-search" type="search" wire:model.live.debounce.300ms="teamSearch" placeholder="{{ __('Suchen') }}" /></x-filament::input.wrapper><x-filament::icon-button wire:click="$set('teamSearch', '')" icon="heroicon-o-x-mark" label="{{ __('Suche leeren') }}" tooltip="{{ __('Suche leeren') }}" :disabled="$teamSearch === ''" /></div></div>
                <div class="ds-options">@foreach($lookups['teams'] as $team)<label class="ds-check"><x-filament::input.checkbox value="{{ $team->id }}" wire:model.live="teams" />{{ $team->team->name }} / {{ $team->season->name }}</label>@endforeach</div>
            </div>
            <div class="ds-field">
                <label class="ds-field">{{ __('Zielgruppe') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="target"><option value="players">{{ __('Spieler') }}</option><option value="staff">{{ __('Staff') }}</option><option value="both">{{ __('Spieler und Staff') }}</option></x-filament::input.select></x-filament::input.wrapper></label>
                <label class="ds-field">{{ __('Datum') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" /></x-filament::input.wrapper></label>
                <label class="ds-check"><x-filament::input.checkbox wire:model.live="signatureRequired" />{{ __('Unterschrift erforderlich') }}</label>
                <label class="ds-check"><x-filament::input.checkbox wire:model.live="portalVisible" />{{ __('Im Mitgliederportal freigeben') }}</label>
                <label class="ds-field">{{ __('Verantwortlicher') }}<x-filament::input.wrapper><x-filament::input wire:model.live.debounce.500ms="common.responsible" maxlength="160" /></x-filament::input.wrapper></label>
                <label class="ds-field">{{ __('Schlüssel Grundnummer') }}<x-filament::input.wrapper><x-filament::input wire:model.live.debounce.500ms="common.base" maxlength="160" /></x-filament::input.wrapper></label>
            </div>
        </div>
        <div class="ds-tools" style="margin-top:1rem"><x-filament::button wire:click="checkPreview" icon="heroicon-o-eye" color="gray" wire:loading.attr="disabled">{{ __('Vorschau prüfen') }}</x-filament::button></div>
    </x-filament::section>
    @if(count($candidates) || $recipientSearch !== '')
    <x-filament::section>
        <x-slot name="heading">{{ __('Empfänger') }}</x-slot>
        <div class="ds-tools"><x-filament::input.wrapper><x-filament::input type="search" aria-label="{{ __('Empfänger suchen') }}" placeholder="{{ __('Empfänger suchen') }}" wire:model.live.debounce.300ms="recipientSearch" /></x-filament::input.wrapper><x-filament::icon-button wire:click="$set('recipientSearch', '')" icon="heroicon-o-x-mark" label="{{ __('Suche leeren') }}" tooltip="{{ __('Suche leeren') }}" :disabled="$recipientSearch === ''" /></div>
        <div class="cu-admin-journal ds-scroll"><table class="ds-table"><thead><tr><th></th><th>{{ __('Mitglied / Mannschaft') }}</th><th>{{ __('Schlüsselnummer') }}</th><th>{{ __('Grundnummer') }}</th><th>{{ __('Verantwortlicher') }}</th></tr></thead><tbody>
        @forelse($filteredCandidates as $person)<tr wire:key="ds-person-{{ $person['id'] }}"><td><x-filament::input.checkbox aria-label="{{ __(':name auswählen', ['name' => $person['name']]) }}" wire:model.live="selected.{{ $person['id'] }}" /></td><td>{{ $person['name'] }}<br><small>{{ $person['teams'] }}</small></td>@foreach(['number'=>__('Schlüsselnummer'),'base'=>__('Grundnummer'),'responsible'=>__('Verantwortlicher')] as $key=>$label)<td><x-filament::input.wrapper><x-filament::input aria-label="{{ __(':label für :name', ['label' => $label, 'name' => $person['name']]) }}" maxlength="160" wire:model.live.debounce.500ms="individual.{{ $person['id'] }}.{{ $key }}" /></x-filament::input.wrapper></td>@endforeach</tr>@empty<tr><td colspan="5">{{ __('Keine passenden Empfänger.') }}</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    @endif
    @if($review)
    <x-filament::section>
        <x-slot name="heading">{{ __('Vorschau /') }} {{ count($review['snapshot']['recipients']) }} {{ __('Empfänger') }}</x-slot>
        @foreach($review['snapshot']['recipients'] as $person)@if(isset($person['error']))<p class="ds-error">{{ $person['member_name'] }}: {{ $person['error'] }}</p>@endif @endforeach
        @if($previewHtml)<iframe title="{{ __('Dokumentvorschau') }}" class="ds-preview" sandbox srcdoc="{{ $previewHtml }}"></iframe>@endif
        @if($review['can_create'] && $can('create') && $can('upload') && $can('send'))
        <div class="ds-tools" style="margin-top:1rem"><label class="ds-check"><x-filament::input.checkbox wire:model.live="acknowledge" />{{ __('Empfänger, Inhalt und Freigabe geprüft') }}</label><x-filament::button wire:click="createSeries" wire:loading.attr="disabled" :disabled="!$acknowledge" icon="heroicon-o-check">{{ __('Serie erstellen') }}</x-filament::button></div>
        @endif
    </x-filament::section>
    @endif
    {{ $this->table }}
    @if($seriesId)
    <x-filament::section>
        <div class="ds-tools"><h2 class="ds-heading">{{ __('Serie #') }}{{ $seriesId }}</h2>@if($can('create') && $can('upload') && $can('send') && $recipients->contains(fn($r) => !$r->document_id))<x-filament::button wire:click="processNext" icon="heroicon-o-play" wire:loading.attr="disabled">{{ __('Nächstes Dokument') }}</x-filament::button>@endif</div>
        <div class="cu-admin-journal ds-scroll"><table class="ds-table"><thead><tr><th>{{ __('Mitglied') }}</th><th>{{ __('Status / Nachweis') }}</th><th>{{ __('Dokumente / Rückgabe') }}</th></tr></thead><tbody>
        @foreach($recipients as $row)
        <tr wire:key="ds-result-{{ $row->id }}"><td>{{ $row->snapshot['member_name'] }}</td><td>
        @php
            $statusLabel = match(true) {
                (bool) $row->signed_document_id => __('Geprüfte PDF-Rückgabe'),
                (bool) $row->signed_upload_id => __('Rückgabe: Übertragung offen'),
                $row->receipt?->state === 'confirmed' => __('Digital bestätigt'),
                (bool) $row->document_id => $row->snapshot['definition']['signature_required'] ? __('Unterschrift offen') : __('Abgelegt'),
                (bool) $row->upload_id => __('Original: Übertragung/Abschluss offen'),
                default => __('Ausstehend'),
            };
            $statusKey = $row->signed_document_id || $row->receipt?->state === 'confirmed' ? 'akzeptiert'
                : ($row->last_error || $row->signed_upload_id ? 'in prüfung' : ($row->document_id && !$row->snapshot['definition']['signature_required'] ? 'freigegeben' : 'offen'));
        @endphp
        <x-filament::badge :color="\App\ClubUnio\Services\StatusAppearance::palette($statusKey)">{{ $statusLabel }}</x-filament::badge>
        @if($row->signed_document_id)<small>{{ __('Upload/Prüfung: Konto #') }}{{ $row->checked_by }}, {{ $row->checked_at?->format('d.m.Y H:i') }}</small>@endif
        @if($row->receipt?->state === 'confirmed')<br><small>{{ __('Digital bestätigt:') }} {{ $row->receipt->confirmation_name }} / {{ $row->receipt->confirmation_kind === 'parent' ? __('Elternvertretung') : __('Mitglied') }}</small>@endif
        @if($row->document_id && !$row->snapshot['portal_visible'])<br><small>{{ __('Nur privates Dossier') }}</small>@endif
        @if($row->last_error)<br><span class="ds-error">{{ __('Wiederholung erforderlich') }}</span>@endif
        </td><td><div class="ds-tools">
        @if($can('download') && $row->document_id && \Illuminate\Support\Facades\Route::has('clubunio.document-series.original'))<x-filament::icon-button tag="a" :href="route('clubunio.document-series.original', [\Filament\Facades\Filament::getTenant()->slug, $seriesId, $row->id])" target="_blank" icon="heroicon-o-document-arrow-down" label="{{ __('Original-PDF') }}" tooltip="{{ __('Original-PDF') }}" />@endif
        @if($can('download') && $row->signed_document_id && \Illuminate\Support\Facades\Route::has('clubunio.document-series.signed'))<x-filament::icon-button tag="a" :href="route('clubunio.document-series.signed', [\Filament\Facades\Filament::getTenant()->slug, $seriesId, $row->id])" target="_blank" icon="heroicon-o-document-check" label="{{ __('Geprüfte PDF-Rückgabe') }}" tooltip="{{ __('Geprüfte PDF-Rückgabe') }}" />@endif
        @if($can('upload') && $can('update') && $row->document_id && $row->snapshot['definition']['signature_required'] && !$row->signed_upload_id)<x-filament::icon-button wire:click="chooseReturn({{ $row->id }})" icon="heroicon-o-arrow-up-tray" label="{{ __('Geprüfte Rückgabe hochladen') }}" tooltip="{{ __('Geprüfte Rückgabe hochladen') }}" />@endif
        @if($can('upload') && $can('update') && $row->signed_upload_id && !$row->signed_document_id)<x-filament::icon-button wire:click="retryReturn({{ $row->id }})" icon="heroicon-o-arrow-path" label="{{ __('Rückgabe fortsetzen') }}" tooltip="{{ __('Rückgabe fortsetzen') }}" />@endif
        </div></td></tr>
        @endforeach
        </tbody></table></div>
        @if($returnRecipient)<form wire:submit="uploadReturn" class="ds-field" style="margin-top:1rem"><h3 class="ds-heading">{{ __('Geprüfte Rückgabe #') }}{{ $returnRecipient }}</h3><input type="file" accept="application/pdf" wire:model="signedFile" aria-label="{{ __('Unterschriebene PDF-Rückgabe') }}"><label class="ds-check"><x-filament::input.checkbox wire:model="returnChecked" />{{ __('Rückgabe und Unterschrift manuell dem Original zugeordnet und geprüft') }}</label><div><x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-arrow-up-tray">{{ __('Rückgabe speichern') }}</x-filament::button></div></form>@endif
    </x-filament::section>
    @endif
</x-filament-panels::page>
