@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'view'))
<h1>Spesenbelege</h1>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'create'))
<a href="{{ route('clubunio.portal.expense.create',['tenant'=>$tenant->slug]) }}">Spesenbeleg erfassen</a>
@endif
<form method="get" class="portal-filters"><label>Mannschaft<input name="q" value="{{ $q }}" maxlength="120"></label>
<label>Status<select name="status"><option value="">Alle</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status')===(string)$status->id)>{{ $status->name }}</option>@endforeach</select></label>
<button class="icon-button" title="Suchen" aria-label="Suchen">@svg('heroicon-o-magnifying-glass')</button></form>
@forelse($expenses as $expense)
<article class="notification-row"><h2><a href="{{ route('clubunio.portal.expense',['tenant'=>$tenant->slug,'expense'=>$expense->id]) }}">{{ $expense->teamSeason?->label }} · {{ $expense->round?->name }}</a></h2>
<p>{{ $expense->member?->display_name }} · {{ $expense->status?->name }} · CHF {{ number_format((float)$expense->active_lines_sum_amount,2,'.',"'") }}</p></article>
@empty<p>Keine Spesenbelege vorhanden.</p>@endforelse
{{ $expenses->links('pagination::simple-default') }}
@endif
@endsection
