@extends('clubunio.portal.layout')
@section('content')
<h1>Meine Bankkonten</h1><p>{{ $member->display_name }}</p>
@if(session('saved'))<p role="status">Bankkonto gespeichert.</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@foreach($accounts as $account)
<section class="notification-row"><h2>{{ $account->bank?->name }} {{ $account->masked_iban }}</h2><p>{{ $account->active ? 'Aktiv':'Inaktiv' }}</p>
<form method="post" action="{{ route('clubunio.portal.bank.save',['tenant'=>$tenant->slug,'account'=>$account->id]) }}">@csrf
<input type="hidden" name="revision" value="{{ $account->revision }}"><input type="hidden" name="bank_id" value="{{ $account->bank_id }}"><input type="hidden" name="active" value="{{ $account->active ? 0:1 }}"><button>{{ $account->active ? 'Deaktivieren':'Aktivieren' }}</button></form>
</section>
@endforeach
<h2>Bankkonto erfassen</h2>
<form method="post" action="{{ route('clubunio.portal.bank.save',['tenant'=>$tenant->slug]) }}" class="registration-form">@csrf
<label>Bank<select name="bank_id" required><option value="">Bitte wählen</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected((string)old('bank_id')===(string)$bank->id)>{{ $bank->name }} {{ $bank->city }}</option>@endforeach</select></label>
<label>IBAN<input name="iban" required maxlength="60" autocomplete="off" value="{{ old('iban') }}"></label>
<input type="hidden" name="active" value="1"><button>Erfassen</button></form>
@endsection
