@php
$t = fn ($key, $replace = []) => trans('marketing.'.$key, $replace);
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $t('page_title') }}</title><meta name="description" content="{{ $t('description') }}">
<meta name="theme-color" content="#164b8a"><meta property="og:title" content="{{ $t('page_title') }}"><meta property="og:description" content="{{ $t('description') }}"><meta property="og:type" content="website">
<link rel="canonical" href="{{ route('clubunio.marketing', ['locale' => $locale]) }}">
@foreach($languages as $code => $name)<link rel="alternate" hreflang="{{ $code }}" href="{{ route('clubunio.marketing', ['locale' => $code]) }}">@endforeach
<link rel="icon" type="image/svg+xml" href="/brand/blue-v1/clubunio-symbol.svg?v=3"><link rel="stylesheet" href="/css/clubunio-marketing.css">
<script src="/js/clubunio-marketing.js" defer></script>
</head>
<body>
<a class="skip" href="#main">{{ $t('skip') }}</a>
<header class="header"><a class="brand" href="{{ route('clubunio.marketing', ['locale' => $locale]) }}"><img src="/brand/blue-v1/clubunio-logo-horizontal.svg" alt="ClubUnio" width="164" height="46"></a>
<nav aria-label="{{ $t('nav') }}"><a href="#features">{{ $t('features') }}</a><a href="#integrations">{{ $t('integrations') }}</a><a href="#ai">{{ $t('ai_nav') }}</a><a href="#about">{{ $t('about') }}</a><a href="#references">{{ $t('references') }}</a><a href="#pricing">{{ $t('prices') }}</a><a href="#extras">{{ $t('extras') }}</a></nav>
<div class="header-actions"><div class="languages" aria-label="{{ $t('language') }}">@foreach($languages as $code => $name)<a lang="{{ $code }}" hreflang="{{ $code }}" href="{{ route('clubunio.marketing', ['locale' => $code]) }}" @if($code === $locale) aria-current="page" @endif>{{ strtoupper($code) }}</a>@endforeach</div><a class="login" href="/login">{{ $t('login') }} ↗</a></div></header>
<main id="main">
<section class="hero wrap"><div class="hero-copy"><p class="eyebrow"><span></span>{{ $t('eyebrow') }}</p><h1>{{ $t('hero_a') }}<br>{{ $t('hero_b') }}<br><em>{{ $t('hero_c') }}</em></h1><p class="lead">{{ $t('hero_text') }}</p><div class="buttons"><a class="button" href="#inquiry">{{ $t('demo') }} <span>↗</span></a><a class="text-link" href="#features">{{ $t('explore') }} ↓</a></div><p class="hero-note">{{ $t('hero_note') }}</p><p class="small-note">{{ $t('origin') }}</p></div>
<div class="hero-visual"><div class="visual-top"><span>CLUBUNIO / FC HORIZON</span><span class="live-dot"></span></div><div class="hero-image">@if(is_file(public_path('images/marketing/'.$locale.'/planning.png')))<img src="/images/marketing/{{ $locale }}/planning.png" alt="{{ $t('screenshot', ['feature' => $t('screen_planning')]) }}" width="1440" height="1000">@else<div class="pitch" aria-hidden="true"><div class="pitch-mid"></div><div class="pitch-circle"></div><div class="pitch-box one"></div><div class="pitch-box two"></div><span class="player p1"></span><span class="player p2"></span><span class="player p3"></span><span class="player p4"></span><span class="player p5"></span></div>@endif</div><div class="visual-bottom"><strong>{{ $t('connected') }}</strong><span>{{ $t('fiction') }}</span></div><div class="floating-tag">bexio <span>↔</span> ClubUnio <span>↔</span> SFV</div></div></section>
<div class="connection-strip"><div class="wrap"><span>{{ $t('connected') }}</span><strong>bexio</strong><span class="strip-mark">SFV / ASF</span><span>{{ $t('app_title') }}</span><span>Signage</span></div></div>
<section class="wrap section" id="features"><p class="eyebrow">{{ $t('section_label') }}</p><div class="section-heading"><h2>{{ $t('feature_title') }}</h2><p>{{ $t('feature_intro') }}</p></div><div class="feature-grid">@foreach(['members' => '01', 'planning' => '02', 'expenses' => '03', 'communication' => '04', 'chat' => '05', 'sponsor' => '06', 'storage' => '07'] as $key => $number)<article class="feature"><span class="feature-number">{{ $number }} /</span><h3>{{ $t($key.'_title') }}</h3><p>{{ $t($key.'_text') }}</p>@if(in_array($key, ['planning', 'expenses'], true))<figure class="feature-preview"><a href="/images/marketing/{{ $locale }}/{{ $key }}.png" data-lightbox aria-label="{{ $t('enlarge') }}: {{ $t('screen_'.$key) }}"><img loading="lazy" src="/images/marketing/{{ $locale }}/{{ $key }}.png" alt="{{ $t('screenshot', ['feature' => $t('screen_'.$key)]) }}" width="1440" height="1000"></a></figure>@endif</article>@endforeach</div>
<div class="spotlights"><article class="app-spot"><span class="eyebrow">CLUBUNIO MOBILE</span><h3>{{ $t('app_title') }}</h3><p>{{ $t('app_text') }}</p><span class="small-note">{{ $t('app_note') }}</span><figure class="app-preview"><a href="/images/marketing/clubunio-app-demo.png" data-lightbox><img src="/images/marketing/clubunio-app-demo.png" alt="{{ $t('app_screenshot_alt') }}" loading="lazy"></a><figcaption>{{ $t('app_screenshot_caption') }}</figcaption></figure></article><article class="signage-spot"><span class="eyebrow">CLUBUNIO SIGNAGE</span><h3>{{ $t('signage_title') }}</h3><p>{{ $t('signage_text') }}</p><figure class="feature-preview"><a href="/images/marketing/{{ $locale }}/signage-display.png" data-lightbox aria-label="{{ $t('enlarge') }}: {{ $t('screen_signage') }}"><img loading="lazy" src="/images/marketing/{{ $locale }}/signage-display.png" alt="{{ $t('screenshot', ['feature' => $t('screen_signage')]) }}" width="1440" height="1000"></a></figure></article></div></section>
<section class="integration-section" id="integrations"><div class="wrap section"><p class="eyebrow">{{ $t('integration_label') }}</p><h2>{{ $t('integration_title') }}</h2><div class="integration-grid">@foreach(['bexio' => 'bexio', 'sfv' => 'SFV / ASF', 'api' => $t('api_title')] as $key => $name)<article><div class="integration-name">{{ $name }}<span>↗</span></div><p>{{ $t($key.'_text') }}</p>@if($key !== 'api')<small>{{ $t($key.'_note') }}</small>@endif</article>@endforeach</div><p class="small-note">{{ $t('integration_note') }}</p><div class="included"><h3>{{ $t('smtp_title') }}</h3><p>{{ $t('smtp_text') }}</p></div></div></section>
<section class="wrap section" id="ai">
    <p class="eyebrow">{{ $t('ai_nav') }}</p>
    <h2>{{ $t('ai_title') }}</h2>
    <p class="section-intro">{{ $t('ai_text') }}</p>
    <p class="small-note">{{ $t('ai_note') }}</p>
</section>
<section class="extras-section" id="about">
    <div class="wrap section">
        <p class="eyebrow">{{ $t('about') }} · SV Sissach</p>
        <h2>{{ $t('about_title') }}</h2>
        <p class="section-intro">{{ $t('about_story') }}</p>
        <p class="section-intro">{{ $t('about_purpose') }}</p>
    </div>
</section>
@if(is_file(public_path('images/marketing/'.$locale.'/members.png')))
<section class="wrap section" id="references" aria-labelledby="references-title">
    <p class="eyebrow">{{ $t('references') }}</p>
    <h2 id="references-title">{{ $t('references_title') }}</h2>
    <div class="feature-grid">
        @foreach(['sv_sissach'] as $reference)
            <article class="feature">
                <h3>{{ $t('reference_'.$reference.'_name') }}</h3>
                <p>{{ $t('reference_'.$reference.'_text') }}</p>
            </article>
        @endforeach
    </div>
</section>
<section class="wrap section" id="product"><p class="eyebrow">CLUBUNIO / FC HORIZON</p><h2>{{ $t('gallery_title') }}</h2><p class="section-intro">{{ $t('gallery_intro') }}</p><div class="gallery">@foreach(['members','expenses','signage'] as $screen)@if(is_file(public_path('images/marketing/'.$locale.'/'.($screen === 'signage' ? 'signage-display' : $screen).'.png')))<figure><a href="/images/marketing/{{ $locale }}/{{ $screen === 'signage' ? 'signage-display' : $screen }}.png" data-lightbox aria-label="{{ $t('enlarge') }}: {{ $t('screen_'.$screen) }}"><img loading="lazy" src="/images/marketing/{{ $locale }}/{{ $screen === 'signage' ? 'signage-display' : $screen }}.png" alt="{{ $t('screenshot', ['feature' => $t('screen_'.$screen)]) }}" width="1440" height="1000"></a><figcaption>{{ $t('screen_'.$screen) }}</figcaption></figure>@endif @endforeach</div></section>
@endif
<section class="wrap section" id="pricing"><p class="eyebrow">{{ $t('pricing_label') }}</p><h2>{{ $t('pricing_title') }}</h2><p class="section-intro">{{ $t('pricing_intro') }}</p>
<div class="pricing-grid">@foreach($plans as $plan)<article class="plan {{ $plan['key'] === 'club' ? 'highlight' : '' }}"><span class="plan-name">{{ $t($plan['key']) }}</span><h3>{{ $t('up_to', ['count' => number_format($plan['members'], 0, '.', $locale === 'de' ? '’' : ',')]) }}</h3><a class="button {{ $plan['key'] !== 'club' ? 'outline' : '' }}" href="#inquiry" data-inquiry-kind="quote" data-inquiry-plan="{{ $plan['key'] }}">{{ $t('choose') }} ↗</a></article>@endforeach</div>
<div class="included"><h3>{{ $t('included') }}</h3><ul>@foreach(explode('|', $t('included_items')) as $item)<li><span aria-hidden="true">✓</span>{{ $item }}</li>@endforeach</ul></div><p class="small-note">{{ $t('count_note') }}</p><p class="small-note">{{ $t('pricing_note') }}</p><div class="enterprise"><strong>{{ $t('enterprise') }}</strong><a href="#inquiry">{{ $t('enterprise_link') }} ↗</a></div>
</section>
<section class="extras-section" id="extras"><div class="wrap section"><p class="eyebrow">{{ $t('extras_label') }}</p><h2>{{ $t('extras_title') }}</h2><p class="section-intro">{{ $t('extras_intro') }}</p><div class="extras-list">@foreach(['domain','design','brand','onboarding','usage'] as $key)<article><span class="extra-arrow" aria-hidden="true">↗</span><div><h3>{{ $t($key.'_title') }}</h3><p>{{ $t($key.'_text') }}</p></div><a href="#inquiry" data-inquiry-kind="quote" class="extra-request">{{ $t('extra_request') }} ↗</a></article>@endforeach</div><p class="small-note">{{ $t('extras_note') }}</p></div></section>
<section class="wrap section faq"><h2>{{ $t('faq_title') }}</h2><div>@for($i=1;$i<=4;$i++)<details><summary>{{ $t('faq'.$i.'_q') }}</summary><p>{{ $t('faq'.$i.'_a') }}</p></details>@endfor</div></section>
<section class="wrap closing" id="inquiry"><p class="eyebrow">{{ $t('closing_label') }}</p><h2>{{ $t('cta_title') }}</h2><p>{{ $t('cta_text') }}</p>@include('clubunio.marketing-inquiry')</section>
</main><footer class="wrap"><div><img src="/brand/blue-v1/clubunio-logo-horizontal.svg" width="140" height="40" alt="ClubUnio"><p>{{ $t('origin') }}</p><p>{{ $t('hosting') }}</p></div><div><a href="mailto:{{ $contact }}">{{ $t('contact') }} ↗</a><p>{{ $t('status') }}</p><p><a href="{{ route('clubunio.marketing.privacy', ['locale' => $locale]) }}">{{ $t('privacy') }}</a></p><small>© {{ date('Y') }} {{ $t('rights') }}</small></div></footer>
<dialog id="marketing-lightbox" class="marketing-lightbox" aria-label="{{ $t('enlarge') }}"><button type="button" aria-label="{{ $t('lightbox_close') }}" autofocus>×</button><img alt=""></dialog>
</body></html>
