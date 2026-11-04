@extends('clubunio.portal.layout')
@section('content')
@php
    $financeActor = auth('portal')->user();
@endphp
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'view'))
<a href="{{ route('clubunio.portal.expenses',['tenant'=>$tenant->slug]) }}">{{ __('Spesenbelege') }}</a>
<h1>{{ $e->teamSeason?->label }}</h1><p>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_rounds', ($e->round ?? []), tenant: $tenant->id ?? null) }} · {{ $e->member?->display_name }} · {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_statuses', ($e->status ?? []), tenant: $tenant->id ?? null) }}</p>
@if(session('saved'))<p role="status">{{ __('Änderung gespeichert.') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@foreach($e->activeLines as $line)
<section class="notification-row"><h2>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_types', ($line->expenseType ?? []), tenant: $tenant->id ?? null) }} {{ __('· CHF') }} {{ number_format((float)$line->amount,2,'.',"'") }}</h2>
<p>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('game_types', ($line->gameType ?? []), tenant: $tenant->id ?? null) }} {{ $line->opponent }} <span class="review-status {{ $line->suggestion_accepted ? 'verified':'changed' }}" title="{{ $line->suggestion_accepted ? __('Vorschlagsbetrag übernommen oder kein Vergleichswert vorhanden'):__('Betrag weicht vom Vorschlag ab') }}" aria-label="{{ $line->suggestion_accepted ? __('Prüfstatus grün'):__('Prüfstatus gelb') }}">@svg($line->suggestion_accepted ? 'heroicon-s-check-circle':'heroicon-s-exclamation-circle')</span></p>
@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'download'))
@foreach(array_values(array_filter(array_map('trim',explode(',',(string)$line->storage_reference)))) as $index=>$filename)
<a target="_blank" rel="noopener" href="{{ route('clubunio.portal.expense.document',['tenant'=>$tenant->slug,'line'=>$line->id,'index'=>$index]) }}">{{ __('Beleg') }} {{ $index+1 }}</a>
@endforeach
@endif
@if($editable && \App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'update'))
<details><summary>{{ __('Position bearbeiten') }}</summary>@include('clubunio.portal.expense-line-form',['line'=>$line])</details>
@endif
</section>
@endforeach
@if($editable && \App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', 'create'))<h2>{{ __('Neue Position') }}</h2>@include('clubunio.portal.expense-line-form',['line'=>null])@endif
@php
    $statuses = $statuses->filter(fn ($status) => \App\ClubUnio\Resources\ProfileUi::profileAllows($financeActor, $tenant, 'expenses', match ($status->code) {
        'F' => 'send', 'Z', 'B' => 'approve', 'R' => 'cancel', 'O' => 'update', default => '__unreviewed',
    }));
@endphp
@if($statuses->isNotEmpty())
<h2>{{ __('Status ändern') }}</h2><form method="post" action="{{ route('clubunio.portal.expense.transition',['tenant'=>$tenant->slug,'expense'=>$e->id]) }}" class="registration-form">@csrf
<input type="hidden" name="revision" value="{{ $e->revision }}">
<label>{{ __('Neuer Status') }}<select name="status_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($statuses as $status)<option value="{{ $status->id }}">{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Prüfvermerk') }}<textarea name="reason" required minlength="10" maxlength="500">{{ old('reason') }}</textarea></label><button>{{ __('Bestätigen') }}</button></form>
@endif
<script src="{{ asset('js/clubunio-expenses.js') }}" defer></script>
@endif
@endsection
