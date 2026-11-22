<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive"><meta name="referrer" content="no-referrer">
<title>{{ __('Member card verification') }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f6fa;color:#182335;font:17px system-ui,-apple-system,sans-serif;padding:24px;min-height:100vh;display:grid;place-items:center}.card{width:min(100%,520px);background:#fff;border:1px solid #dce3eb;border-radius:24px;overflow:hidden;box-shadow:0 8px 32px #192b4410}.stripe{height:8px;background:var(--club-color,#475569)}.content{padding:28px}header{display:flex;gap:16px;align-items:center;margin-bottom:28px}.logo{width:64px;height:64px;object-fit:contain}.initials{width:64px;height:64px;border-radius:16px;background:#edf1f6;display:grid;place-items:center;font-size:24px;font-weight:700}h1{font-size:22px;margin:0 0 6px}h2{font-size:23px;overflow-wrap:anywhere;margin:20px 0}p{line-height:1.5;margin:12px 0}.muted{color:#5c697c;font-size:14px}.verdict{padding:16px;border-radius:14px;font-size:21px;font-weight:700;border:1px solid}.valid{background:#e8f6eb;color:#185c2a;border-color:#b5dbbe}.invalid{background:#fcebea;color:#a1251e;border-color:#efbcb8}.unknown{background:#fff5dd;color:#725000;border-color:#ead6a0}.languages{display:flex;flex-wrap:wrap;gap:16px;border-top:1px solid #e1e6ed;margin-top:24px;padding-top:20px}a{color:#164b8a;text-underline-offset:4px}time{font-variant-numeric:tabular-nums}small{display:block;line-height:1.5}
</style>
</head>
<body>
<main class="card" style="--club-color:{{ $card['Brand']['PrimaryColor'] ?? '#475569' }}">
<div class="stripe"></div><div class="content">
<header>
@if(!empty($card['Brand']['LogoData']))
<img class="logo" src="data:{{ $card['Brand']['LogoMime'] }};base64,{{ $card['Brand']['LogoData'] }}" alt="{{ __('Club logo') }}">
@else
<div class="initials" aria-hidden="true">{{ mb_strtoupper(mb_substr($card['TenantName'] ?? '', 0, 2)) }}</div>
@endif
<div><h1>{{ __('Member card verification') }}</h1>@if(!empty($card['TenantName']))<span>{{ $card['TenantName'] }}</span>@endif</div>
</header>
@if($unavailable)
<div class="verdict unknown">{{ __('Verification currently unavailable') }}</div>
<p>{{ __('Please try again. A status could not be verified.') }}</p>
@elseif($card['Valid'])
<div class="verdict valid">{{ __('Membership currently valid') }}</div>
<h2>{{ $card['Name'] }}</h2>
<p>{{ __('The membership was checked against the club’s current data.') }}</p>
@else
<div class="verdict invalid">{{ __('Member card not valid') }}</div>
<p>{{ __('This card is unknown, revoked or the membership is not currently eligible.') }}</p>
@endif
<p class="muted">{{ __('Checked at') }} <time datetime="{{ $card['CheckedAt'] }}">{{ \Carbon\CarbonImmutable::parse($card['CheckedAt'])->setTimezone('Europe/Zurich')->format($locale === 'de' ? 'd.m.Y H:i:s T' : 'Y-m-d H:i:s T') }}</time></p>
<small class="muted">{{ __('This verification confirms membership status only. Partner discounts depend on the partner’s terms. Compare the name with the person presenting the card.') }}</small>
<p><a href="{{ '?lang='.rawurlencode($locale) }}">{{ __('Refresh verification') }}</a></p>
<nav class="languages" aria-label="{{ __('Language') }}">
@foreach(config('clubunio-languages.enabled', []) as $code => $label)<a href="{{ '?lang='.rawurlencode($code) }}" @if($code === $locale) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav>
</div></main>
</body></html>
