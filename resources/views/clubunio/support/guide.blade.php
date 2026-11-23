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
