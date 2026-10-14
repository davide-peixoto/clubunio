<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans;font-size:11px;color:#202020}h1{font-size:22px}table{width:100%;border-collapse:collapse;margin-top:25px}th,td{padding:9px 5px;border-bottom:1px solid #ccc;text-align:left}.amount{text-align:right}.note{margin-top:30px;color:#555}
</style></head><body>
<h1>{{ $tenant->name }}</h1>
<h2>Rechnungsübersicht #{{ $invoice->legacy_id ?? $invoice->id }}</h2>
<p>{{ $invoice->member?->display_name }}<br>{{ $invoice->season?->name }}<br>{{ $invoice->workflowStatus?->name }}</p>
<table><thead><tr><th>Position</th><th class="amount">Brutto CHF</th><th class="amount">Rabatt CHF</th><th class="amount">Endbetrag CHF</th></tr></thead><tbody>
@foreach($invoice->activeLines as $line)
<tr><td>{{ $line->description }}</td><td class="amount">{{ number_format((float)$line->gross_amount,2,'.',"'") }}</td><td class="amount">{{ number_format((float)$line->discount,2,'.',"'") }}</td><td class="amount">{{ number_format((float)$line->net_amount,2,'.',"'") }}</td></tr>
@endforeach
</tbody><tfoot>
@php($taxSummary = app(\App\ClubUnio\Services\InvoiceTaxService::class)->summary($invoice))
@if($invoice->tax_mode === 'inclusive')
<tr><td colspan="3">Enthaltene Steuer CHF</td><td class="amount">{{ number_format((float)$taxSummary['tax'],2,'.',"'") }}</td></tr>
@endif
@if($invoice->tax_mode === 'inclusive' || $taxSummary['rounding_cents'] !== 0)
<tr><td colspan="3">Rundung CHF</td><td class="amount">{{ number_format($taxSummary['rounding_cents']/100,2,'.',"'") }}</td></tr>
@endif
<tr><th colspan="3">Gesamt CHF</th><th class="amount">{{ number_format($taxSummary['total_cents']/100,2,'.',"'") }}</th></tr></tfoot></table>
<p>{{ $invoice->notes }}</p><p class="note">Interne ClubUnio-Vorschau. Keine Zahlungsaufforderung und kein Zahlungsnachweis.</p>
</body></html>
