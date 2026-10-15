<div class="space-y-4">
@forelse($events as $event)
    <dl class="border-b pb-4">
        <dt>Ereignis</dt><dd>{{ $event->event_key }} (Revision {{ $event->revision }})</dd>
        <dt>Status</dt><dd>{{ $event->delivery_state === 'held' && (str_starts_with($event->event_key, 'registration.staff.') || in_array($event->event_key, ['registration.review', 'registration.created'], true)) ? 'Interne Benachrichtigung; keine E-Mail vorgesehen' : (['held'=>'Zurückgehalten: Empfänger oder aktive Vorlage prüfen','ready'=>'Zum Testversand vorgemerkt','sending'=>'Versand läuft / Ergebnis noch offen','accepted'=>'Vom Mailserver angenommen (nur admin@svsissach.ch)','failed'=>'Versand fehlgeschlagen','skipped'=>'Übersprungen'][$event->delivery_state] ?? $event->delivery_state) }}</dd>
        <dt>Erstellt</dt><dd>{{ \Carbon\CarbonImmutable::parse($event->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</dd>
        @if($event->finished_at)<dt>Abgeschlossen</dt><dd>{{ \Carbon\CarbonImmutable::parse($event->finished_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</dd>@endif
        @if($event->error_code)<dt>Fehlercode</dt><dd>{{ $event->error_code }}</dd>@endif
    </dl>
@empty
    <p>Keine Benachrichtigungen vorhanden.</p>
@endforelse
</div>
