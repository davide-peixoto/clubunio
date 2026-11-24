@php
$t = fn ($key, $replace = []) => trans('marketing.'.$key, $replace);
$screenRoot = '/images/marketing/2026-11-24/'.$locale.'/';
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $t('page_title') }}</title><meta name="description" content="{{ $t('description') }}">
<meta name="theme-color" content="#164b8a"><meta property="og:title" content="{{ $t('page_title') }}"><meta property="og:description" content="{{ $t('description') }}"><meta property="og:type" content="website">
<link rel="canonical" href="{{ route('clubunio.marketing', ['locale' => $locale]) }}">
@foreach($languages as $code => $name)<link rel="alternate" hreflang="{{ $code }}" href="{{ route('clubunio.marketing', ['locale' => $code]) }}">@endforeach
<link rel="icon" type="image/svg+xml" href="/brand/blue-v1/clubunio-symbol.svg?v=3"><link rel="stylesheet" href="/css/clubunio-marketing.css?v=20261124">
<script src="/js/clubunio-marketing.js?v=20261124" defer></script>
</head>
<body>
<a class="skip" href="#main">{{ $t('skip') }}</a>
<header class="header"><a class="brand" href="{{ route('clubunio.marketing', ['locale' => $locale]) }}"><img src="/brand/blue-v1/clubunio-logo-horizontal.svg" alt="ClubUnio" width="164" height="46"></a>
<nav aria-label="{{ $t('nav') }}"><a href="#features">{{ $t('features') }}</a><a href="#app">{{ $t('app_nav') }}</a><a href="#integrations">{{ $t('integrations') }}</a><a href="#ai">{{ $t('ai_nav') }}</a><a href="#about">{{ $t('about') }}</a><a href="#references">{{ $t('references') }}</a><a href="#pricing">{{ $t('prices') }}</a><a href="#extras">{{ $t('extras') }}</a></nav>
<div class="header-actions"><div class="languages" aria-label="{{ $t('language') }}">@foreach($languages as $code => $name)<a lang="{{ $code }}" hreflang="{{ $code }}" href="{{ route('clubunio.marketing', ['locale' => $code]) }}" @if($code === $locale) aria-current="page" @endif>{{ strtoupper($code) }}</a>@endforeach</div><a class="login" href="/login">{{ $t('login') }} ↗</a></div></header>
<main id="main">
<section class="hero wrap"><div class="hero-copy"><p class="eyebrow"><span></span>{{ $t('eyebrow') }}</p><h1>{{ $t('hero_a') }}<br>{{ $t('hero_b') }}<br><em>{{ $t('hero_c') }}</em></h1><p class="lead">{{ $t('hero_text') }}</p><div class="buttons"><a class="button" href="#inquiry">{{ $t('demo') }} <span>↗</span></a><a class="text-link" href="#features">{{ $t('explore') }} ↓</a></div><p class="hero-note">{{ $t('hero_note') }}</p><p class="small-note">{{ $t('origin') }}</p></div>
<div class="hero-visual"><div class="visual-top"><span>CLUBUNIO / FC HORIZON</span></div><div class="hero-image"><a href="{{ $screenRoot }}dashboard.jpg" data-lightbox aria-label="{{ $t('enlarge') }} – {{ $t('screen_dashboard') }}"><img src="{{ $screenRoot }}dashboard.jpg" alt="{{ $t('screen_dashboard') }}" width="2290" height="1120" fetchpriority="high"></a></div><div class="visual-bottom"><strong>{{ $t('connected') }}</strong><span>{{ $t('fiction') }}</span></div></div></section>
<div class="connection-strip"><div class="wrap"><span>{{ $t('connected') }}</span><strong>bexio</strong><span class="strip-mark">SFV / ASF</span><span>{{ $t('app_title') }}</span><span>Signage</span></div></div>
<section class="wrap section" id="features">
    <p class="eyebrow">{{ $t('section_label') }}</p>
    <div class="section-heading"><h2>{{ $t('feature_title') }}</h2><p>{{ $t('feature_intro') }}</p></div>
    <p class="section-intro">{{ $t('backend_note') }}</p>
    <div class="feature-grid backend-features">
        @foreach(['workspace','members','planning','facilities','expenses','tasks','approvals','trainer','chat','communication','sponsor','storage','social','veo','campaign'] as $key)
            <article class="feature">
                <h3>{{ $t($key.'_title') }}</h3>
                <p>{{ $t($key.'_text') }}</p>
                @if(in_array($key, ['workspace','members','planning','facilities','expenses','tasks','social','veo'], true))
                    @include('clubunio.marketing-screenshot', ['screen' => $key])
                @endif
            </article>
        @endforeach
    </div>
    <p class="small-note">{{ $t('development_note') }}</p>
</section>
<section class="extras-section" id="app">
    <div class="wrap section">
        <p class="eyebrow">{{ $t('app_label') }}</p>
        <h2>{{ $t('app_title') }}</h2>
        <p class="section-intro">{{ $t('app_text') }}</p>
        <p>{{ $t('app_note') }}</p>
        <div class="app-feature-grid">
            @foreach(['app_planning','app_chat','app_expense','app_profile'] as $screen)
                <article>
                    <h3>{{ $t($screen.'_title') }}</h3>
                    <p>{{ $t($screen.'_text') }}</p>
                    @include('clubunio.marketing-screenshot', ['screen' => $screen])
                </article>
            @endforeach
        </div>
        <p class="small-note">{{ $t('app_demo_note') }}</p>
        <p>{{ $t('app_support_text') }}</p>
    </div>
</section>
<section class="wrap section signage-section" id="signage">
    <div><p class="eyebrow">CLUBUNIO SIGNAGE</p><h2>{{ $t('signage_title') }}</h2><p class="section-intro">{{ $t('signage_text') }}</p></div>
    @include('clubunio.marketing-screenshot', ['screen' => 'signage'])
</section>
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
<section class="wrap section" id="product">
    <h2>{{ $t('gallery_title') }}</h2>
    <p class="section-intro">{{ $t('gallery_intro') }}</p>
    <div class="gallery">
        @foreach(['dashboard','workspace','facilities','tasks'] as $screen)
            @include('clubunio.marketing-screenshot', ['screen' => $screen])
        @endforeach
    </div>
</section>
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
