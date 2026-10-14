<div class="clubunio-signage-feeds" wire:init="refreshFeeds" wire:poll.60s="refreshFeeds">
    @foreach (['weather' => 'Wetter aktuell', 'instagram' => 'Instagram'] as $key => $title)
        @php($feed = $feeds[$key] ?? ['state' => 'loading', 'data' => null, 'updated' => null])
        <section>
            <h2>{{ $title }}</h2>
            @if ($feed['state'] === 'loading')<p role="status">Wird geladen …</p>
            @elseif ($feed['state'] === 'disabled')<p>{{ $key === 'instagram' ? 'Instagram ist nicht aktiviert.' : 'Wetter ist nicht aktiviert.' }}</p>
            @elseif ($feed['state'] === 'unavailable')<p role="status">Momentan nicht verfügbar.</p>
            @else
                @if ($feed['state'] === 'stale')<p role="status">Aktualisierung nicht möglich. Letzter verfügbarer Stand.</p>@endif
                <p class="clubunio-feed-time">Abgerufen {{ $feed['updated'] }}</p>
                @if ($key === 'weather')
                    <h3>{{ $feed['data']['label'] }}</h3>
                    <p class="clubunio-weather-temperature">{{ $feed['data']['temperature'] }} °C</p>
                    <p>{{ $feed['data']['text'] }} · Wind {{ $feed['data']['wind'] }} km/h</p>
                    <p>Modellzeit {{ str_replace('T', ' ', $feed['data']['time']) }} (Schweiz)</p>
                    <div class="clubunio-weather-days">@foreach ($feed['data']['days'] as $day)
                        <div><strong>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d.m.') }}</strong><span>{{ $day['text'] }}</span><span>{{ $day['min'] }} / {{ $day['max'] }} °C</span></div>
                    @endforeach</div>
                @else
                    <div class="clubunio-instagram-posts">@forelse ($feed['data'] as $item)
                        <article x-data="{ failed: false }">
                            <a href="{{ $item['link'] }}" target="_blank" rel="noopener noreferrer">
                                <img src="{{ $item['image'] }}" alt="Instagram-Beitrag" loading="lazy" decoding="async" referrerpolicy="no-referrer" x-show="!failed" x-on:error="failed = true" x-on:load="failed = false">
                                <span x-cloak x-show="failed">Beitrag auf Instagram öffnen</span>
                                <p>{{ $item['caption'] }}</p>
                            </a>
                        </article>
                    @empty<p>Keine Beiträge verfügbar.</p>@endforelse</div>
                @endif
            @endif
            @if ($key === 'weather')<p class="clubunio-feed-time">Quelle: MeteoSchweiz via <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo</a>. Modellprognose, keine Platzfreigabe.</p>@endif
        </section>
    @endforeach
</div>
