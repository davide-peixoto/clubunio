@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><div><h1>{{ __('Meine Bankkonten') }}</h1><p>{{ $member->display_name }}</p></div></div>
@if(session('saved'))<p role="status">{{ __('Bankkonto gespeichert.') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="cu-portal-list">
    <div class="table-scroll">
        <table>
            <thead><tr><th scope="col">{{ __('Bank') }}</th><th scope="col">{{ __('IBAN') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ trans('portal-shell.actions') }}</th></tr></thead>
            <tbody>
                @forelse($accounts as $account)
                    <tr>
                        <td>{{ $account->bank?->name }}</td><td>{{ $account->masked_iban }}</td>
                        <td><span class="cu-portal-status" data-state="{{ $account->active ? 'active' : 'inactive' }}">{{ $account->active ? __('Aktiv') : __('Inaktiv') }}</span></td>
                        <td><form method="post" action="{{ route('clubunio.portal.bank.save', [$tenant->slug, $account->id]) }}">@csrf
                            <input type="hidden" name="revision" value="{{ $account->revision }}"><input type="hidden" name="bank_id" value="{{ $account->bank_id }}"><input type="hidden" name="active" value="{{ $account->active ? 0 : 1 }}">
                            <button class="cu-portal-secondary">{{ $account->active ? __('Deaktivieren') : __('Aktivieren') }}</button>
                        </form></td>
                    </tr>
                @empty
                    @include('clubunio.portal.empty-row', ['columns' => 4, 'icon' => 'heroicon-o-building-library', 'message' => trans('portal-shell.no_banks')])
                @endforelse
            </tbody>
        </table>
    </div>
</div>
<section class="cu-portal-form-section">
<h2>{{ __('Bankkonto erfassen') }}</h2>
<form method="post" action="{{ route('clubunio.portal.bank.save',['tenant'=>$tenant->slug]) }}" class="registration-form">@csrf
<label>{{ __('Bank') }}<select name="bank_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($banks as $bank)<option value="{{ $bank->id }}" @selected((string)old('bank_id')===(string)$bank->id)>{{ $bank->name }} {{ $bank->city }}</option>@endforeach</select></label>
<label>{{ __('IBAN') }}<input name="iban" required maxlength="60" autocomplete="off" value="{{ old('iban') }}"></label>
<input type="hidden" name="active" value="1"><button class="primary">@svg('heroicon-o-plus') {{ __('Erfassen') }}</button></form>
</section>
@endsection
