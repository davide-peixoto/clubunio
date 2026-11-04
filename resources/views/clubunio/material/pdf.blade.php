<!doctype html><html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><style>
body { font-family: DejaVu Sans; font-size: 10pt; color: #222; } h1 { font-size: 18pt; } table { width:100%; border-collapse:collapse; } th,td { text-align:left; padding:7px; border-bottom:1px solid #ccc; overflow-wrap:break-word; } .note { white-space:pre-wrap; } small { font-size:8pt; }
</style></head><body>
<p>{{ $receipt->snapshot['club'] }}</p><h1>{{ __('Materialübergabe / Empfangsbestätigung') }}</h1>
<h2>{{ $receipt->snapshot['title'] }}</h2>
<p>{{ __('Quittung') }} {{ $receipt->id }} · {{ $receipt->snapshot['member'] }}<br>{{ __('Übergabe:') }} {{ $receipt->snapshot['handover_on'] }} {{ __('· Ausgestellt durch') }} {{ $receipt->snapshot['issuer'] }}</p>
<table><thead><tr><th>{{ __('Gegenstand') }}</th><th>{{ __('Anzahl') }}</th><th>{{ __('Nummer / Grösse') }}</th><th>{{ __('Zustand') }}</th></tr></thead><tbody>
@foreach($receipt->snapshot['items'] as $item)<tr><td>{{ $item['description'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $item['reference'] ?? '' }}</td><td>{{ $item['condition'] ?? '' }}</td></tr>@endforeach
</tbody></table><p class="note">{{ $receipt->snapshot['note'] }}</p>
@if($receipt->state === 'cancelled')<h2>{{ __('Storniert') }}</h2><p>{{ $receipt->cancellation_reason }}</p>
@elseif($receipt->confirmed_at)<h2>{{ __('Empfang bestätigt') }}</h2><p>{{ $receipt->confirmation_text }}</p>
<p>{{ $receipt->confirmation_name }} ({{ $receipt->confirmation_kind === 'parent' ? __('Elternvertretung') : __('Mitglied') }})<br>{{ $receipt->confirmed_at->timezone('Europe/Zurich')->format('d.m.Y H:i:s T') }}</p>
@else<h2>{{ __('Empfang noch nicht bestätigt') }}</h2>@endif
<small>{{ __('Elektronische Bestätigung im angemeldeten Vereinsportal. Keine qualifizierte elektronische Signatur.') }}<br>{{ __('Inhaltsreferenz:') }} {{ $receipt->snapshot_hash }}</small>
</body></html>
