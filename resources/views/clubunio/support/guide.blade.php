<p>{{ $item['body'] }}</p>
@if(!empty($item['image_url']))
    <img src="{{ $item['image_url'] }}" alt="{{ $item['title'] }}" loading="lazy" style="display:block;max-width:100%;height:auto;border-radius:12px">
@endif
@if(!empty($item['screenshot_url']))
    <figure><img src="{{ $item['screenshot_url'] }}" alt="{{ $item['title'] }}" loading="lazy" style="display:block;max-width:100%;max-height:640px;height:auto"><figcaption>{{ $item['screenshot_caption'] }}</figcaption></figure>
@endif
@if(!empty($item['steps']))
    <ol>
        @foreach($item['steps'] as $step)
            <li><strong>{{ $step['title'] }}</strong><p>{{ $step['body'] }}</p></li>
        @endforeach
    </ol>
@endif
@foreach($item['notes'] ?? [] as $note)<p>{{ $note }}</p>@endforeach
@if(!empty($item['video_url']))
    <video controls preload="none" playsinline style="width:100%;max-width:960px" aria-label="{{ $item['title'] }}">
        <source src="{{ $item['video_url'] }}" type="video/mp4">
        @if(!empty($item['captions_url']))<track kind="captions" src="{{ $item['captions_url'] }}" srclang="{{ app()->getLocale() }}" label="{{ app()->getLocale() }}" default>@endif
    </video>
    <p><a href="{{ $item['video_url'] }}">{{ trans('support.video') }}</a></p>
@endif

@if(!empty($item['recording_url']))
    <p>{{ $item['recording_caption'] }}</p>
    <video controls preload="none" playsinline style="max-width:100%;max-height:640px" aria-label="{{ $item['recording_caption'] }}"><source src="{{ $item['recording_url'] }}" type="video/mp4"></video>
@endif
