@php
    $t = fn (string $key) => trans('marketing.'.$key);
    $privacy = fn (string $key) => trans('marketing_privacy.'.$key);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $t('privacy_title') }} · ClubUnio</title>
    <link rel="stylesheet" href="/css/clubunio-marketing.css">
    @foreach($languages as $code => $name)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ route('clubunio.marketing.privacy', ['locale' => $code]) }}">
    @endforeach
</head>
<body>
<header class="header">
    <a href="{{ route('clubunio.marketing', ['locale' => $locale]) }}">{{ $t('back') }}</a>
    <nav class="languages" aria-label="{{ $t('language') }}">
        @foreach($languages as $code => $name)
            <a href="{{ route('clubunio.marketing.privacy', ['locale' => $code]) }}" lang="{{ $code }}" @if($code === $locale) aria-current="page" @endif>{{ $name }}</a>
        @endforeach
    </nav>
</header>
<main class="wrap section privacy-page">
    <h2>{{ $t('privacy_title') }}</h2>
    <p>{{ $privacy('updated') }}</p>
    <p>{{ $privacy('privacy_intro') }}</p>
    @foreach(['controller', 'website', 'appdata', 'permissions', 'hosting', 'retention', 'rights', 'stores'] as $section)
        <section id="{{ $section }}">
            <h3>{{ $privacy($section.'_title') }}</h3>
            <p>{{ $privacy($section.'_text') }}</p>
            @if(in_array($section, ['controller', 'rights'], true))
                <address>ClubUnio GmbH<br>Hofstettenweg 13<br>CH-4450 Sissach<br><a href="mailto:{{ $contact }}">{{ $contact }}</a></address>
            @endif
        </section>
    @endforeach
</main>
</body>
</html>
