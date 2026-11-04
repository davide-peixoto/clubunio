<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/clubunio-chat.css') }}?v=1">
    <script type="module" src="{{ asset('js/clubunio-chat.js') }}?v=1"></script>
    @php
        $labels = [];
        foreach (['Anhang ansehen', 'Keine Reaktionen', 'Text übernehmen', 'Kamera'] as $label) $labels[$label] = __($label);
        foreach (['Chats', 'Unterhaltungen suchen', 'Neue Direktnachricht', 'Nachricht', 'Senden', 'Erneut versuchen', 'Abbrechen', 'Schliessen', 'Laden', 'Keine Unterhaltungen', 'Unterhaltung auswählen', 'Ältere Nachrichten', 'Stummschalten', 'Stummschaltung aufheben', 'Anhängen', 'Umfrage', 'Termin', 'Einstellungen', 'Moderation', 'Bearbeiten', 'Entfernen', 'Melden', 'Reaktionen', 'Abstimmen', 'Speichern', 'Kontakt suchen', 'Keine Kontakte gefunden', 'Vereinsankündigungen', 'Mannschaft', 'Trainer', 'Terminbesprechung', 'Direktnachricht', 'Nur Lesen', 'Zurück', 'Datei auswählen', 'Anhang wird geprüft', 'Anhang nicht verfügbar', 'Nachricht entfernt', 'Nachricht entfernen?', 'Entwurf verwerfen?', 'Neue Nachricht', 'Frage', 'Antwortmöglichkeiten', 'Eine Antwort pro Zeile', 'Mehrfachauswahl', 'Abstimmungsende', 'Termin auswählen', 'Empfängervorschau', 'Empfänger: :count', 'Veröffentlichen', 'Grund', 'Erledigen', 'Keine offenen Meldungen', 'Personen', 'Mehr anzeigen', 'Blockieren', 'Blockierung aufheben', 'Blockierte Kontakte', 'Übersetzung aktivieren', 'Zielsprache', 'Deutsch', 'Englisch', 'KI-Textassistenz', 'Korrigieren', 'Freundlicher formulieren', 'Kürzen', 'Prüfen', 'Übersetzen', 'Text zur Verarbeitung an den KI-Anbieter senden', 'Übernehmen', 'Verbindung unterbrochen. Ihr Entwurf bleibt erhalten.', 'Der Zugriff hat sich geändert. Bitte die Seite neu laden.', 'Die Daten wurden geändert. Bitte aktualisieren und erneut prüfen.', 'Die Funktion ist derzeit nicht verfügbar.', 'Bitte prüfen Sie Ihre Eingaben.', 'Nachricht konnte nicht gesendet werden. Erneut versuchen verwendet dieselbe Nachrichten-ID.', 'Anhang abgelehnt', 'Anhang herunterladen', 'Umfrage beendet', 'Antworten: :count', 'Aktualisieren', 'Vorschau abgelaufen. Bitte Empfänger erneut prüfen.', 'Nachricht erfolgreich gesendet', 'Keine Termine gefunden', 'Auswahl entfernen', 'Bitte zuerst den ausstehenden Versand klären.'] as $label) $labels[$label] = __($label);
    @endphp
    <section class="cu-chat" data-cu-chat data-config="{{ json_encode($this->chatConfig(), JSON_THROW_ON_ERROR) }}" data-labels="{{ json_encode($labels, JSON_THROW_ON_ERROR) }}" aria-label="{{ __('Chats') }}" wire:ignore>
        <div class="cu-chat-alert" data-error role="alert" hidden></div>
        <aside class="cu-chat-list">
            <div class="cu-chat-toolbar">
                <input type="search" data-search placeholder="{{ __('Unterhaltungen suchen') }}" aria-label="{{ __('Unterhaltungen suchen') }}">
                <button type="button" data-action="contacts" title="{{ __('Neue Direktnachricht') }}" aria-label="{{ __('Neue Direktnachricht') }}"><x-filament::icon icon="heroicon-o-plus" /></button>
                <button type="button" data-action="settings" title="{{ __('Einstellungen') }}" aria-label="{{ __('Einstellungen') }}"><x-filament::icon icon="heroicon-o-cog-6-tooth" /></button>
            </div>
            <div class="cu-chat-conversations" data-conversations></div>
        </aside>
        <div class="cu-chat-conversation">
            <header class="cu-chat-toolbar">
                <button type="button" class="cu-chat-back" data-action="back" title="{{ __('Zurück') }}" aria-label="{{ __('Zurück') }}"><x-filament::icon icon="heroicon-o-arrow-left" /></button>
                <h2 data-title>{{ __('Unterhaltung auswählen') }}</h2>
                <button type="button" data-action="refresh" title="{{ __('Aktualisieren') }}" aria-label="{{ __('Aktualisieren') }}"><x-filament::icon icon="heroicon-o-arrow-path" /></button>
                <button type="button" data-action="mute" title="{{ __('Stummschalten') }}" aria-label="{{ __('Stummschalten') }}" hidden><x-filament::icon icon="heroicon-o-bell-slash" /></button>
                <button type="button" data-action="reports" title="{{ __('Moderation') }}" aria-label="{{ __('Moderation') }}" hidden><x-filament::icon icon="heroicon-o-shield-check" /></button>
                <button type="button" data-action="block" title="{{ __('Blockieren') }}" aria-label="{{ __('Blockieren') }}" hidden><x-filament::icon icon="heroicon-o-no-symbol" /></button>
            </header>
            <div class="cu-chat-history" data-history tabindex="0" aria-label="{{ __('Nachricht') }}">
                <button type="button" data-action="older" hidden>{{ __('Ältere Nachrichten') }}</button>
                <div data-messages></div>
            </div>
            <p class="cu-chat-status" data-status role="status" aria-live="polite"></p>
            <form class="cu-chat-composer" data-composer hidden>
                <div class="cu-chat-selection" data-selection hidden></div>
                <label class="cu-chat-sr" for="cu-chat-text">{{ __('Nachricht') }}</label>
                <textarea id="cu-chat-text" data-text maxlength="4000" rows="3" placeholder="{{ __('Nachricht') }}" required></textarea>
                <div class="cu-chat-toolbar">
                    <button type="button" data-action="attachment" title="{{ __('Anhängen') }}" aria-label="{{ __('Anhängen') }}"><x-filament::icon icon="heroicon-o-paper-clip" /></button>
                    <button type="button" data-action="camera" title="{{ __('Kamera') }}" aria-label="{{ __('Kamera') }}"><x-filament::icon icon="heroicon-o-camera" /></button>
                    <button type="button" data-action="poll" title="{{ __('Umfrage') }}" aria-label="{{ __('Umfrage') }}"><x-filament::icon icon="heroicon-o-chart-bar" /></button>
                    <button type="button" data-action="event" title="{{ __('Termin') }}" aria-label="{{ __('Termin') }}"><x-filament::icon icon="heroicon-o-calendar-days" /></button>
                    <button type="button" data-action="ai" title="{{ __('KI-Textassistenz') }}" aria-label="{{ __('KI-Textassistenz') }}"><x-filament::icon icon="heroicon-o-sparkles" /></button>
                    <button type="button" data-action="discard" title="{{ __('Auswahl entfernen') }}" aria-label="{{ __('Auswahl entfernen') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button>
                    <span class="cu-chat-spacer"></span>
                    <button type="submit" class="cu-chat-primary" data-send title="{{ __('Senden') }}" aria-label="{{ __('Senden') }}"><x-filament::icon icon="heroicon-o-paper-airplane" /></button>
                </div>
                <input type="file" data-file accept="image/jpeg,image/png,image/webp,application/pdf" hidden>
                <input type="file" data-camera accept="image/jpeg,image/png,image/webp" capture="environment" hidden>
            </form>
        </div>
        <dialog data-dialog aria-labelledby="cu-chat-dialog-title">
            <header class="cu-chat-toolbar"><h2 id="cu-chat-dialog-title"></h2><button type="button" data-action="close-dialog" title="{{ __('Schliessen') }}" aria-label="{{ __('Schliessen') }}"><x-filament::icon icon="heroicon-o-x-mark" /></button></header>
            <div data-dialog-body></div>
        </dialog>
        <template data-icon="edit"><x-filament::icon icon="heroicon-o-pencil-square" /></template>
        <template data-icon="delete"><x-filament::icon icon="heroicon-o-trash" /></template>
        <template data-icon="report"><x-filament::icon icon="heroicon-o-flag" /></template>
        <template data-icon="reactions"><x-filament::icon icon="heroicon-o-face-smile" /></template>
        <template data-icon="translate"><x-filament::icon icon="heroicon-o-language" /></template>
        <template data-icon="download"><x-filament::icon icon="heroicon-o-arrow-down-tray" /></template>
        <template data-icon="preview"><x-filament::icon icon="heroicon-o-eye" /></template>
        <template data-icon="retry"><x-filament::icon icon="heroicon-o-arrow-path" /></template>
    </section>
</x-filament-panels::page>
