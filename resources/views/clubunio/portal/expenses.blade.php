@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'view'))
<div class="page-heading">
    <h1>{{ __('Spesenbelege') }}</h1>
    @if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'create'))
        <a class="primary" href="{{ route('clubunio.portal.expense.create', $tenant->slug) }}">@svg('heroicon-o-plus') {{ __('Spesenbeleg erfassen') }}</a>
    @endif
</div>
<div class="cu-portal-list">
<form method="get" class="toolbar"><label>{{ __('Mannschaft') }}<input type="search" name="q" value="{{ $q }}" maxlength="120"></label>
<label>{{ __('Status') }}<select name="status"><option value="">{{ __('Alle') }}</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status')===(string)$status->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<button class="icon-button" title="{{ __('Filtern') }}" aria-label="{{ __('Filtern') }}">@svg('heroicon-o-funnel')</button>
@if($q !== '' || request()->filled('status'))<a class="cu-portal-reset" href="{{ route('clubunio.portal.expenses', $tenant->slug) }}">{{ __('Zurücksetzen') }}</a>@endif
</form>
<div class="table-scroll">
    <table>
        <thead><tr><th scope="col">{{ __('Mannschaft') }}</th><th scope="col">{{ __('Mitglied') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="cu-portal-numeric">{{ __('Betrag (CHF)') }}</th></tr></thead>
        <tbody>
            @forelse($expenses as $expense)
                <tr>
                    <td><a href="{{ route('clubunio.portal.expense', [$tenant->slug, $expense->id]) }}">{{ $expense->teamSeason?->label ?? __('Nicht zugeordnet') }}</a><small>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_rounds', $expense->round ?? [], tenant: $tenant->id) }}</small></td>
                    <td>{{ $expense->member?->display_name }}</td>
                    <td><span class="cu-portal-status" data-state="{{ $expense->status?->code }}">{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_statuses', $expense->status ?? [], tenant: $tenant->id) }}</span></td>
                    <td class="cu-portal-numeric">{{ number_format((float) $expense->active_lines_sum_amount, 2, '.', "'") }}</td>
                </tr>
            @empty
                @include('clubunio.portal.empty-row', ['columns' => 4, 'icon' => 'heroicon-o-receipt-percent', 'message' => __('Keine Spesenbelege vorhanden.')])
            @endforelse
        </tbody>
    </table>
</div>
{{ $expenses->links('pagination::simple-default') }}
</div>
@endif
@endsection
