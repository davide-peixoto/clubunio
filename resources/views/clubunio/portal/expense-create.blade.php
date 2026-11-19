@extends('clubunio.portal.layout')
@section('content')
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'expenses', 'create'))
<div class="page-heading">
    <div><h1>{{ __('Spesenbeleg erfassen') }}</h1><p>{{ $member->display_name }}</p></div>
    <a class="cu-portal-secondary" href="{{ route('clubunio.portal.expenses', $tenant->slug) }}">@svg('heroicon-o-arrow-left') {{ __('Spesenbelege') }}</a>
</div>
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="cu-portal-form-section">
<form method="post" action="{{ route('clubunio.portal.expense.store',['tenant'=>$tenant->slug]) }}" class="registration-form">@csrf
<input type="hidden" name="member_id" value="{{ $member->id }}">
<label>{{ __('Mannschaft / Saison') }}<select name="team_season_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected((string)old('team_season_id')===(string)$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>{{ __('Spielrunde') }}<select name="round_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($rounds as $round)<option value="{{ $round->id }}" @selected((string)old('round_id')===(string)$round->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_rounds', $round, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Bankkonto') }}<select name="bank_account_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected((string)old('bank_account_id')===(string)$bank->id)>{{ $bank->bank?->name }} {{ $bank->masked_iban }}</option>@endforeach</select></label>
<label>{{ __('Bemerkungen') }}<textarea name="notes" maxlength="4000">{{ old('notes') }}</textarea></label><button class="primary">{{ __('Erfassen') }}</button></form>
</div>
@endif
@endsection
