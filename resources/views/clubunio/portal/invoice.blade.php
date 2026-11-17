@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'invoices', 'view'))
<div class="page-heading">
    <div>
        <h1>{{ __('Rechnung') }} {{ $record->legacy_id ?? $record->id }}</h1>
        <p>{{ $record->member?->display_name }} · {{ $record->season?->name }} · {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('invoice_statuses', ($record->status ?? []), tenant: $tenant->id ?? null) }}</p>
    </div>
    <a class="cu-portal-secondary" href="{{ route('clubunio.portal.invoices', [$tenant->slug, 'member' => $record->member_id]) }}">@svg('heroicon-o-arrow-left') {{ __('Rechnungen') }}</a>
</div>
<h2>{{ __('Positionen') }}</h2>
<div class="cu-portal-list"><div class="table-scroll"><table><thead><tr><th>{{ __('Bezeichnung') }}</th><th>{{ __('Brutto CHF') }}</th><th>{{ __('Rabatt CHF') }}</th><th>{{ __('Endbetrag CHF') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
@forelse($record->activeLines as $line)
<tr><td>{{ $line->description }}</td><td>{{ number_format((float)$line->gross_amount,2,'.',"'") }}</td><td>{{ number_format((float)$line->discount,2,'.',"'") }}</td><td>{{ number_format((float)$line->net_amount,2,'.',"'") }}</td><td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('invoice_line_statuses', ($line->status ?? []), tenant: $tenant->id ?? null) }}</td></tr>
@empty
    @include('clubunio.portal.empty-row', ['columns' => 5, 'icon' => 'heroicon-o-document-text', 'message' => __('Keine Positionen vorhanden.')])
@endforelse
</tbody><tfoot>
@if($record->rounding_cents)<tr><td colspan="3">{{ __('Rundung CHF') }}</td><td>{{ number_format((int)$record->rounding_cents/100,2,'.',"'") }}</td><td></td></tr>@endif
<tr><th colspan="3">{{ __('Gesamt') }}</th><td>{{ number_format((float)$record->active_lines_sum_net_amount + (int)$record->rounding_cents/100,2,'.',"'") }}</td><td></td></tr></tfoot></table></div></div>
@endif
@endsection
