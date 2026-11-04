<div class="space-y-6">
    <p role="status">{{ __('Vorschau zum aktuellen Status. Kein Versand im Testbetrieb.') }}</p>
    <section class="clubunio-message-section"><h3 class="font-semibold">{{ __('Fachliche Empfänger') }}</h3>
        @forelse ($plan['recipients'] as $recipient)<p>{{ $recipient['name'] }} · {{ $recipient['role'] }}</p>@empty<p>{{ __('Keine Empfänger ermittelt.') }}</p>@endforelse
    </section>
    @foreach ($plan['variants'] ?? [app()->getLocale() => $plan['messages']] as $locale => $messages)
    <section lang="{{ str_replace('_', '-', $locale) }}" class="space-y-4">
        <h2 class="font-semibold" lang="{{ str_replace('_', '-', app()->getLocale()) }}">{{ __('Sprache') }}: <span lang="{{ str_replace('_', '-', $locale) }}">{{ config('clubunio-languages.enabled')[$locale] ?? $locale }}</span></h2>
    @foreach ($messages as $channel => $message)
        <section class="clubunio-message-section"><h3 class="font-semibold">{{ $channel === 'email' ? __('E-Mail') : __('Push') }}</h3>
            @if ($message)
                <p class="mt-2 font-semibold">{{ $message['title'] }}</p>
                <p class="clubunio-message-body">{{ $message['body'] }}</p>
                <p class="mt-2 text-sm">{{ __('Vorlagenversion') }} {{ $message['revision'] }}</p>
            @else<p>{{ __('Vorlage fehlt oder ist deaktiviert.') }}</p>@endif
        </section>
    @endforeach
    </section>
    @endforeach
</div>
