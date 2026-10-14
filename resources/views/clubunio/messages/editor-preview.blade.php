<div class="space-y-4">
    <div class="clubunio-message-section"><strong>Platzhalter</strong><div class="clubunio-placeholders">@foreach ($variables as $variable)<code>&#123;&#123; {{ $variable }} &#125;&#125;</code>@endforeach</div></div>
    <div class="clubunio-message-section"><strong>Vorschau mit Beispieldaten</strong>
        @if ($error)<p role="alert">{{ $error }}</p>@endif
        @if ($preview)<h3 class="mt-3 font-semibold">{{ $preview['title'] }}</h3><p class="clubunio-message-body">{{ $preview['body'] }}</p>@endif
    </div>
</div>
