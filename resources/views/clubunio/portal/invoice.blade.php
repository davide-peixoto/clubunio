@extends('clubunio.portal.layout')
@section('content')
<a class="icon-button" href="{{ route('clubunio.portal.invoices',['tenant'=>$tenant->slug,'member'=>$record->member_id]) }}" title="Rechnungen" aria-label="Rechnungen">@svg('heroicon-o-arrow-left')</a>
<h1>Rechnung {{ $record->legacy_id ?? $record->id }}</h1>
<p>{{ $record->member?->display_name }} · {{ $record->season?->name }} · {{ $record->status?->name }}</p>
<h2>Positionen</h2>
<div class="table-scroll"><table><thead><tr><th>Bezeichnung</th><th>Brutto CHF</th><th>Rabatt CHF</th><th>Endbetrag CHF</th><th>Status</th></tr></thead><tbody>
@forelse($record->activeLines as $line)
<tr><td>{{ $line->description }}</td><td>{{ number_format((float)$line->gross_amount,2,'.',"'") }}</td><td>{{ number_format((float)$line->discount,2,'.',"'") }}</td><td>{{ number_format((float)$line->net_amount,2,'.',"'") }}</td><td>{{ $line->status?->name }}</td></tr>
@empty<tr><td colspan="5">Keine Positionen vorhanden.</td></tr>@endforelse
</tbody><tfoot>
@if($record->rounding_cents)<tr><td colspan="3">Rundung CHF</td><td>{{ number_format((int)$record->rounding_cents/100,2,'.',"'") }}</td><td></td></tr>@endif
<tr><th colspan="3">Gesamt</th><td>{{ number_format((float)$record->active_lines_sum_net_amount + (int)$record->rounding_cents/100,2,'.',"'") }}</td><td></td></tr></tfoot></table></div>
@endsection
