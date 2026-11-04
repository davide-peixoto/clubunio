@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'invoices', 'view'))
<div class="page-heading"><h1>{{ __('Rechnungen') }}</h1><span>{{ $invoices->total() }} {{ __('Einträge') }}</span></div>
<form method="get" class="toolbar">
    <div><label for="q">{{ __('Name / Rechnungsnummer') }}</label><input type="search" id="q" name="q" value="{{ $q }}" maxlength="120"></div>
    <div><label for="status">{{ __('Status') }}</label><select id="status" name="status"><option value="">{{ __('Alle') }}</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status') === (string)$status->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('invoice_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach</select></div>
    <div><label for="season">{{ __('Saison') }}</label><select id="season" name="season"><option value="">{{ __('Alle') }}</option>@foreach($seasons as $season)<option value="{{ $season->id }}" @selected((string)request('season') === (string)$season->id)>{{ $season->name }}</option>@endforeach</select></div>
    @if(request('member'))<input type="hidden" name="member" value="{{ request('member') }}">@endif
    <button class="icon-button" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button>
    <a class="icon-button" href="{{ route('clubunio.portal.invoices',['tenant'=>$tenant->slug]) }}" title="{{ __('Filter zurücksetzen') }}" aria-label="{{ __('Filter zurücksetzen') }}">@svg('heroicon-o-x-mark')</a>
</form>
<div class="table-scroll"><table><thead><tr><th>{{ __('Rechnung') }}</th><th>{{ __('Mitglied') }}</th><th>{{ __('Saison') }}</th><th>{{ __('Status') }}</th><th>{{ __('Betrag CHF') }}</th></tr></thead><tbody>
@forelse($invoices as $invoice)
<tr><td><a href="{{ route('clubunio.portal.invoice',['tenant'=>$tenant->slug,'invoice'=>$invoice->id]) }}">{{ $invoice->legacy_id ?? $invoice->id }}</a></td><td>{{ $invoice->member?->display_name }}</td><td>{{ $invoice->season?->name }}</td><td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('invoice_statuses', ($invoice->status ?? []), tenant: $tenant->id ?? null) }}</td><td>{{ number_format((float)$invoice->active_lines_sum_net_amount + (int)$invoice->rounding_cents/100,2,'.',"'") }}</td></tr>
@empty<tr><td colspan="5">{{ __('Keine Rechnungen gefunden.') }}</td></tr>@endforelse
</tbody></table></div>
<div class="pagination">{{ $invoices->links('pagination::simple-default') }}</div>
@endif
@endsection
