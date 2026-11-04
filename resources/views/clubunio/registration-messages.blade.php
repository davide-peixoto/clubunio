<div class="space-y-4">
@forelse($events as $event)
    <dl class="border-b pb-4">
        <dt>{{ __('Ereignis') }}</dt><dd>{{ $event->event_key }} {{ __('(Revision') }} {{ $event->revision }})</dd>
        <dt>{{ __('Status') }}</dt><dd>{{ $event->delivery_state === 'held' && (str_starts_with($event->event_key, 'registration.staff.') || in_array($event->event_key, ['registration.review', 'registration.created'], true)) ? __('Interne Benachrichtigung; keine E-Mail vorgesehen') : (['held'=>__('Zurückgehalten: Empfänger oder aktive Vorlage prüfen'),'ready'=>__('Zum Testversand vorgemerkt'),'sending'=>__('Versand läuft / Ergebnis noch offen'),'accepted'=>__('Vom Mailserver angenommen (nur admin@svsissach.ch)'),'failed'=>__('Versand fehlgeschlagen'),'skipped'=>__('Übersprungen')][$event->delivery_state] ?? $event->delivery_state) }}</dd>
        <dt>{{ __('Erstellt') }}</dt><dd>{{ \Carbon\CarbonImmutable::parse($event->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</dd>
        @if($event->finished_at)<dt>{{ __('Abgeschlossen') }}</dt><dd>{{ \Carbon\CarbonImmutable::parse($event->finished_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</dd>@endif
        @if($event->error_code)<dt>{{ __('Fehlercode') }}</dt><dd>{{ $event->error_code }}</dd>@endif
    </dl>
@empty
    <p>{{ __('Keine Benachrichtigungen vorhanden.') }}</p>
@endforelse
</div>
