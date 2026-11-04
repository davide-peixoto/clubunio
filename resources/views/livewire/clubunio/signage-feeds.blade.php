<div class="clubunio-signage-feeds" wire:init="refreshFeeds" wire:poll.60s="refreshFeeds">
    @foreach (['weather' => __('Wetter aktuell'), 'instagram' => 'Instagram'] as $key => $title)
        @php($feed = $feeds[$key] ?? ['state' => 'loading', 'data' => null, 'updated' => null])
        <section>
            <h2>{{ $title }}</h2>
            @if ($feed['state'] === 'loading')<p role="status">{{ __('Wird geladen …') }}</p>
            @elseif ($feed['state'] === 'disabled')<p>{{ $key === 'instagram' ? __('Instagram ist nicht aktiviert.') : __('Wetter ist nicht aktiviert.') }}</p>
            @elseif ($feed['state'] === 'unavailable')<p role="status">{{ __('Momentan nicht verfügbar.') }}</p>
            @else
                @if ($feed['state'] === 'stale')<p role="status">{{ __('Aktualisierung nicht möglich. Letzter verfügbarer Stand.') }}</p>@endif
                <p class="clubunio-feed-time">{{ __('Abgerufen :time', ['time' => $feed['updated']]) }}</p>
                @if ($key === 'weather')
                    <h3>{{ $feed['data']['label'] }}</h3>
                    <p class="clubunio-weather-temperature">{{ $feed['data']['temperature'] }} °C</p>
                    <p>{{ __($feed['data']['text']) }} · {{ __('Wind') }} {{ $feed['data']['wind'] }} km/h</p>
                    <p>{{ __('Modellzeit :time (Schweiz)', ['time' => str_replace('T', ' ', $feed['data']['time'])]) }}</p>
                    <div class="clubunio-weather-days">@foreach ($feed['data']['days'] as $day)
                        <div><strong>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d.m.') }}</strong><span>{{ __($day['text']) }}</span><span>{{ $day['min'] }} / {{ $day['max'] }} °C</span></div>
                    @endforeach</div>
                @else
                    <div class="clubunio-instagram-posts">@forelse ($feed['data'] as $item)
                        <article x-data="{ failed: false }">
                            <a href="{{ $item['link'] }}" target="_blank" rel="noopener noreferrer">
                                <img src="{{ $item['image'] }}" alt="{{ __('Instagram-Beitrag') }}" loading="lazy" decoding="async" referrerpolicy="no-referrer" x-show="!failed" x-on:error="failed = true" x-on:load="failed = false">
                                <span x-cloak x-show="failed">{{ __('Beitrag auf Instagram öffnen') }}</span>
                                <p>{{ $item['caption'] }}</p>
                            </a>
                        </article>
                    @empty<p>{{ __('Keine Beiträge verfügbar.') }}</p>@endforelse</div>
                @endif
            @endif
            @if ($key === 'weather')<p class="clubunio-feed-time">{{ __('Quelle:') }} MeteoSchweiz via <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo</a>. {{ __('Modellprognose, keine Platzfreigabe.') }}</p>@endif
        </section>
    @endforeach
</div>
