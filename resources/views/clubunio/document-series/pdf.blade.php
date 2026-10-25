<!doctype html>
<html lang="de">
<head><meta charset="utf-8"><style>
@page { margin: 17mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #222; }
header { border-bottom: 2px solid #b71c36; padding-bottom: 8px; }
h1 { font-size: 18pt; margin: 15px 0; }
table { border-collapse: collapse; width: 100%; margin: 12px 0; table-layout: fixed; }
td, th { border: 1px solid #aaa; padding: 5px; text-align: left; overflow-wrap: anywhere; }
tr { page-break-inside: avoid; }
.text { white-space: pre-wrap; overflow-wrap: anywhere; }
.signature { page-break-inside: avoid; margin-top: 22px; line-height: 2.5; }
</style></head>
<body>
<header>{{ $snapshot['club'] }}</header>
<h1>{{ $snapshot['definition']['title'] }}</h1>
<p>{{ $snapshot['member_name'] }}<br>{{ $snapshot['values']['Team.Name'] }}<br>{{ $snapshot['values']['Dokument.Datum'] }}</p>
@if($snapshot['definition']['items'])
<table><thead><tr><th style="width:9%">Anzahl</th><th style="width:36%">Artikel</th><th style="width:21%">Grösse / Initialen</th><th>CHF/Stk.</th><th>CHF</th></tr></thead><tbody>
@php($total = 0)
@foreach($snapshot['definition']['items'] as $item)
@php($sum = $item['quantity'] * $item['price_cents'])
@php($total += $sum)
<tr><td>{{ $item['quantity'] }}</td><td>{{ trim(($item['reference'] ?? '').' '.($item['brand'] ?? '').' '.$item['description']) }}</td><td>{{ trim(($item['size'] ?? '').' '.($item['initials'] ?? '')) }}</td><td>{{ number_format($item['price_cents']/100, 2, '.', "'") }}</td><td>{{ number_format($sum/100, 2, '.', "'") }}</td></tr>
@endforeach
</tbody><tfoot><tr><td colspan="4">Materialwert CHF</td><td>{{ number_format($total/100, 2, '.', "'") }}</td></tr></tfoot></table>
@endif
<div class="text">{{ $snapshot['definition']['text'] }}</div>
@if($snapshot['definition']['signature_required'])
<div class="signature">Ort / Datum: __________________________________<br>Unterschrift bei Übernahme: __________________________________<br>Stempel / Unterschrift Verein bei Rückgabe: __________________________________</div>
@endif
</body></html>
