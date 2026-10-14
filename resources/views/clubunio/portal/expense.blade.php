@extends('clubunio.portal.layout')
@section('content')
<a href="{{ route('clubunio.portal.expenses',['tenant'=>$tenant->slug]) }}">Spesenbelege</a>
<h1>{{ $e->teamSeason?->label }}</h1><p>{{ $e->round?->name }} · {{ $e->member?->display_name }} · {{ $e->status?->name }}</p>
@if(session('saved'))<p role="status">Änderung gespeichert.</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@foreach($e->activeLines as $line)
<section class="notification-row"><h2>{{ $line->expenseType?->name }} · CHF {{ number_format((float)$line->amount,2,'.',"'") }}</h2>
<p>{{ $line->gameType?->name }} {{ $line->opponent }} <span class="review-status {{ $line->suggestion_accepted ? 'verified':'changed' }}" title="{{ $line->suggestion_accepted ? 'Vorschlagsbetrag übernommen oder kein Vergleichswert vorhanden':'Betrag weicht vom Vorschlag ab' }}" aria-label="{{ $line->suggestion_accepted ? 'Prüfstatus grün':'Prüfstatus gelb' }}">@svg($line->suggestion_accepted ? 'heroicon-s-check-circle':'heroicon-s-exclamation-circle')</span></p>
@foreach(array_values(array_filter(array_map('trim',explode(',',(string)$line->storage_reference)))) as $index=>$filename)
<a target="_blank" rel="noopener" href="{{ route('clubunio.portal.expense.document',['tenant'=>$tenant->slug,'line'=>$line->id,'index'=>$index]) }}">Beleg {{ $index+1 }}</a>
@endforeach
@if($editable)
<details><summary>Position bearbeiten</summary>@include('clubunio.portal.expense-line-form',['line'=>$line])</details>
@endif
</section>
@endforeach
@if($editable)<h2>Neue Position</h2>@include('clubunio.portal.expense-line-form',['line'=>null])@endif
@if($statuses->isNotEmpty())
<h2>Status ändern</h2><form method="post" action="{{ route('clubunio.portal.expense.transition',['tenant'=>$tenant->slug,'expense'=>$e->id]) }}" class="registration-form">@csrf
<input type="hidden" name="revision" value="{{ $e->revision }}">
<label>Neuer Status<select name="status_id" required><option value="">Bitte wählen</option>@foreach($statuses as $status)<option value="{{ $status->id }}">{{ $status->name }}</option>@endforeach</select></label>
<label>Prüfvermerk<textarea name="reason" required minlength="10" maxlength="500">{{ old('reason') }}</textarea></label><button>Bestätigen</button></form>
@endif
<script src="{{ asset('js/clubunio-expenses.js') }}" defer></script>
@endsection
