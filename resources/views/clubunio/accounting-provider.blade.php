@extends('clubunio.workspace.layout', ['module' => 'bexio'])
@section('native-content')
<header class="fi-header">
    <h1 class="fi-header-heading">{{ trans('accounting_provider.title') }}</h1>
</header>
<form method="post" action="{{ route('clubunio.accounting-provider.update') }}">
    @csrf
    @include('clubunio.workspace.context')
    <input type="hidden" name="expected" value="{{ $provider }}">
    <fieldset>
        <legend>{{ trans('accounting_provider.provider') }}</legend>
        @foreach($providers as $option)
            <label>
                <input type="radio" name="provider" value="{{ $option }}" @checked(old('provider', $provider) === $option) required>
                {{ trans('accounting_provider.options.'.$option) }}
            </label>
        @endforeach
    </fieldset>
    <x-filament::button type="submit">{{ trans('accounting_provider.save') }}</x-filament::button>
</form>
@if($provider === \App\ClubUnio\Services\AccountingProvider::ERPNEXT)
    <x-filament::button tag="a" :href="\App\ClubUnio\Pages\ErpNextConnection::getUrl()">
        {{ trans('accounting_provider.configure_erpnext') }}
    </x-filament::button>
@endif
@endsection
