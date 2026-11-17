@extends('clubunio.platform.layout')
@section('content')
<section class="platform-login"><h1>{{ __('Plattformverwaltung') }}</h1><form method="post" action="{{ route('clubunio.platform.login') }}">@csrf
<label>{{ __('Benutzername') }}<input name="username" autocomplete="username" required maxlength="255" value="{{ old('username') }}"></label>
<label>{{ __('Passwort') }}<input name="password" type="password" autocomplete="current-password" required maxlength="255"></label>
<button class="primary">@svg('heroicon-o-lock-closed') {{ __('Anmelden') }}</button></form>
@if (app(\App\ClubUnio\Services\AccountSecurity::class)->enabled())
    <a href="{{ route('clubunio.security.show', ['mode' => 'platform']) }}">{{ trans('account-security.strong_login') }}</a>
@endif
</section>
@endsection
