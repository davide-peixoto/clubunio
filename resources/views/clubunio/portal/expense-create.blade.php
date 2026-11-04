@extends('clubunio.portal.layout')
@section('content')
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'expenses', 'create'))
<h1>{{ __('Spesenbeleg erfassen') }}</h1><p>{{ $member->display_name }}</p>
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="post" action="{{ route('clubunio.portal.expense.store',['tenant'=>$tenant->slug]) }}" class="registration-form">@csrf
<input type="hidden" name="member_id" value="{{ $member->id }}">
<label>{{ __('Mannschaft / Saison') }}<select name="team_season_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected((string)old('team_season_id')===(string)$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>{{ __('Spielrunde') }}<select name="round_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($rounds as $round)<option value="{{ $round->id }}" @selected((string)old('round_id')===(string)$round->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_rounds', $round, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Bankkonto') }}<select name="bank_account_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected((string)old('bank_account_id')===(string)$bank->id)>{{ $bank->bank?->name }} {{ $bank->masked_iban }}</option>@endforeach</select></label>
<label>{{ __('Bemerkungen') }}<textarea name="notes" maxlength="4000">{{ old('notes') }}</textarea></label><button>{{ __('Erfassen') }}</button></form>
@endif
@endsection
