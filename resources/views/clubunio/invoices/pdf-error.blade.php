<!DOCTYPE html><html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ __('PDF nicht verfügbar') }}</title>
<style>body{font:16px system-ui,sans-serif;color:#303030;padding:24px;line-height:1.6}h1{font-size:22px}small{overflow-wrap:anywhere;color:#666}</style></head><body>
<h1>{{ __('PDF nicht verfügbar') }}</h1><p>{{ $message }}</p><small>{{ __('Referenz:') }} {{ $reference }}</small></body></html>
