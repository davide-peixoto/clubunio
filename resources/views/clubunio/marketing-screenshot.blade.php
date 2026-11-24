@php
    $phone = str_starts_with($screen, 'app_');
    $filename = str_replace('_', '-', $screen).($phone ? '.png' : '.jpg');
@endphp
<figure class="product-shot {{ $phone ? 'phone-shot' : '' }}">
    <a href="{{ $screenRoot.$filename }}" data-lightbox aria-label="{{ $t('enlarge') }} – {{ $t('screen_'.$screen) }}">
        <img loading="lazy" src="{{ $screenRoot.$filename }}" alt="{{ $t('screenshot', ['feature' => $t('screen_'.$screen)]) }}" width="{{ $phone ? 1080 : 2290 }}" height="{{ $phone ? 2400 : 1120 }}">
    </a>
    <figcaption>{{ $t('screen_'.$screen) }}</figcaption>
</figure>
