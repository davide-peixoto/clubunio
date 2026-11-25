@if($reactEntry=app(\App\ClubUnio\Services\ReactAssets::class)->entry($entry))
    @foreach(app(\App\ClubUnio\Services\ReactAssets::class)->preloads($entry) as $preload)
        <link rel="modulepreload" href="{{ asset($preload) }}">
    @endforeach
    <script type="module" src="{{ asset($reactEntry) }}"></script>
@else
    <script src="{{ $legacy }}" defer></script>
@endif
