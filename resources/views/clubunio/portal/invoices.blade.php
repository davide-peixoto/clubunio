@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'invoices', 'view'))
<div class="page-heading"><h1>Rechnungen</h1><span>{{ $invoices->total() }} Einträge</span></div>
<form method="get" class="toolbar">
    <div><label for="q">Name / Rechnungsnummer</label><input type="search" id="q" name="q" value="{{ $q }}" maxlength="120"></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="">Alle</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status') === (string)$status->id)>{{ $status->name }}</option>@endforeach</select></div>
    <div><label for="season">Saison</label><select id="season" name="season"><option value="">Alle</option>@foreach($seasons as $season)<option value="{{ $season->id }}" @selected((string)request('season') === (string)$season->id)>{{ $season->name }}</option>@endforeach</select></div>
    @if(request('member'))<input type="hidden" name="member" value="{{ request('member') }}">@endif
    <button class="icon-button" title="Suchen" aria-label="Suchen">@svg('heroicon-o-magnifying-glass')</button>
    <a class="icon-button" href="{{ route('clubunio.portal.invoices',['tenant'=>$tenant->slug]) }}" title="Filter zurücksetzen" aria-label="Filter zurücksetzen">@svg('heroicon-o-x-mark')</a>
</form>
<div class="table-scroll"><table><thead><tr><th>Rechnung</th><th>Mitglied</th><th>Saison</th><th>Status</th><th>Betrag CHF</th></tr></thead><tbody>
@forelse($invoices as $invoice)
<tr><td><a href="{{ route('clubunio.portal.invoice',['tenant'=>$tenant->slug,'invoice'=>$invoice->id]) }}">{{ $invoice->legacy_id ?? $invoice->id }}</a></td><td>{{ $invoice->member?->display_name }}</td><td>{{ $invoice->season?->name }}</td><td>{{ $invoice->status?->name }}</td><td>{{ number_format((float)$invoice->active_lines_sum_net_amount + (int)$invoice->rounding_cents/100,2,'.',"'") }}</td></tr>
@empty<tr><td colspan="5">Keine Rechnungen gefunden.</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $invoices->links('pagination::simple-default') }}</div>
@endif
@endsection
