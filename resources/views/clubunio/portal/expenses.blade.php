@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'view'))
<h1>{{ __('Spesenbelege') }}</h1>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'create'))
<a href="{{ route('clubunio.portal.expense.create',['tenant'=>$tenant->slug]) }}">{{ __('Spesenbeleg erfassen') }}</a>
@endif
<form method="get" class="portal-filters"><label>{{ __('Mannschaft') }}<input name="q" value="{{ $q }}" maxlength="120"></label>
<label>{{ __('Status') }}<select name="status"><option value="">{{ __('Alle') }}</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status')===(string)$status->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<button class="icon-button" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button></form>
@forelse($expenses as $expense)
<article class="notification-row"><h2><a href="{{ route('clubunio.portal.expense',['tenant'=>$tenant->slug,'expense'=>$expense->id]) }}">{{ $expense->teamSeason?->label }} · {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_rounds', ($expense->round ?? []), tenant: $tenant->id ?? null) }}</a></h2>
<p>{{ $expense->member?->display_name }} · {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_statuses', ($expense->status ?? []), tenant: $tenant->id ?? null) }} {{ __('· CHF') }} {{ number_format((float)$expense->active_lines_sum_amount,2,'.',"'") }}</p></article>
@empty<p>{{ __('Keine Spesenbelege vorhanden.') }}</p>@endforelse
{{ $expenses->links('pagination::simple-default') }}
@endif
@endsection
