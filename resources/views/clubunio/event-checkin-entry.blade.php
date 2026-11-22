<!doctype html>
<html lang="{{ $locale }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('Event check-in') }}</title>
<style>body{margin:0;padding:28px 18px;background:#f4f6fa;color:#152237;font:17px system-ui,sans-serif}.card{max-width:480px;margin:8vh auto;background:#fff;border:1px solid #dce3ed;border-radius:22px;padding:28px}h1{font-size:26px;line-height:1.2;margin:0 0 20px}p{line-height:1.6}.apps{display:grid;gap:12px;margin:26px 0}.button{display:block;border-radius:12px;background:#164b8a;color:#fff;text-decoration:none;padding:16px;text-align:center;font-weight:700;overflow-wrap:anywhere}.muted{font-size:14px;color:#5c697c}.languages{display:flex;gap:18px;flex-wrap:wrap;border-top:1px solid #dce3ed;margin-top:24px;padding-top:18px}.languages a{color:#164b8a}</style>
</head><body><main class="card">
<h1>{{ __('Event check-in') }}</h1>
<p>{{ __('Open the event in your app, sign in if needed and confirm your own attendance.') }}</p>
<div class="apps">@foreach($apps as $app)<a class="button" href="{{ $app['url'] }}">{{ __('Open in :app', ['app' => $app['name']]) }}</a>@endforeach</div>
<p class="muted">{{ __('Scanning this code does not record attendance. Confirmation in the app is required.') }}</p>
<p class="muted">{{ __('If the app does not open, install or update your club app and scan the code again.') }}</p>
<nav class="languages" aria-label="{{ __('Language') }}">@foreach(config('clubunio-languages.enabled', []) as $code => $label)<a href="?lang={{ rawurlencode($code) }}" @if($code === $locale) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
</main></body></html>
