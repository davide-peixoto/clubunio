<div class="space-y-6">
    <p role="status">Vorschau zum aktuellen Status. Kein Versand im Testbetrieb.</p>
    <section class="clubunio-message-section"><h3 class="font-semibold">Fachliche Empfänger</h3>
        @forelse ($plan['recipients'] as $recipient)<p>{{ $recipient['name'] }} · {{ $recipient['role'] }}</p>@empty<p>Keine Empfänger ermittelt.</p>@endforelse
    </section>
    @foreach ($plan['messages'] as $channel => $message)
        <section class="clubunio-message-section"><h3 class="font-semibold">{{ $channel === 'email' ? 'E-Mail' : 'Push' }}</h3>
            @if ($message)
                <p class="mt-2 font-semibold">{{ $message['title'] }}</p>
                <p class="clubunio-message-body">{{ $message['body'] }}</p>
                <p class="mt-2 text-sm">Vorlagenversion {{ $message['revision'] }}</p>
            @else<p>Vorlage fehlt oder ist deaktiviert.</p>@endif
        </section>
    @endforeach
</div>
