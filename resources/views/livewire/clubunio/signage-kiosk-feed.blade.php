@php($item = $feeds[$feed] ?? ['state'=>'loading','data'=>null,'updated'=>null])
<section class="sg-panel {{ $feed==='weather'?'sg-weather':'sg-instagram' }}" wire:init="refreshFeeds" wire:poll.60s="refreshFeeds">
    <header><h2>@svg($feed==='weather'?'heroicon-o-cloud':'heroicon-o-camera') {{ $feed==='weather'?'Wetter':'Vereinsnews' }}</h2>@if($item['updated'])<small>{{ substr($item['updated'],-5) }}</small>@endif</header>
    <div class="sg-panel-body {{ $feed==='instagram'?'sg-instagram-body':'' }}">
    @if($item['state']==='loading')<p class="sg-empty">Wird geladen …</p>
    @elseif($item['state']==='disabled')<p class="sg-empty">{{ $feed==='instagram'?'Instagram ist nicht aktiviert.':'Wetter ist nicht aktiviert.' }}</p>
    @elseif($item['state']==='unavailable')<p class="sg-empty">Momentan nicht verfügbar.</p>
    @else
        @if($item['state']==='stale')<p class="sg-stale">Letzter verfügbarer Stand</p>@endif
        @if($feed==='weather')
            <div class="sg-weather-current">@svg(in_array($item['data']['text'],['Klar','Überwiegend klar'])?'heroicon-o-sun':'heroicon-o-cloud')<strong>{{ $item['data']['temperature'] }}°</strong><span>{{ $item['data']['label'] }}<br>{{ $item['data']['text'] }}<br>{{ $item['data']['wind'] }} km/h</span></div>
            <div class="sg-forecast">@foreach($item['data']['days'] as $day)<div><strong>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d.m.') }}</strong><span>{{ $day['text'] }}</span><span>{{ $day['max'] }}° <small>{{ $day['min'] }}°</small></span></div>@endforeach</div>
            <p class="sg-weather-source">MeteoSchweiz via <a href="https://open-meteo.com/" target="_blank" rel="noopener noreferrer">Open-Meteo</a></p>
        @else
            <div class="sg-instagram-slides">@forelse($item['data'] as $post)<article><a href="{{ $post['link'] }}" target="_blank" rel="noopener noreferrer" title="{{ mb_substr($post['caption'],0,180) }}"><img src="{{ $post['image'] }}" alt="{{ mb_substr($post['caption']?:'Instagram-Beitrag',0,180) }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"></a></article>@empty<p class="sg-empty">Keine Beiträge verfügbar.</p>@endforelse</div>
        @endif
    @endif
    </div>
</section>
