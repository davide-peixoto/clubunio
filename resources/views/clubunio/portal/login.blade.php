@extends('clubunio.portal.layout')
@section('content')
<section class="portal-login">
    <h1>{{ __('Mitgliederbereich') }}</h1>
    <form method="post" action="{{ route('clubunio.portal.login') }}">
        @csrf
        <label for="username">{{ __('Benutzername') }}</label>
        <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required maxlength="255">
        <label for="password">{{ __('Passwort') }}</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        @if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
        <button class="primary" type="submit">{{ __('Anmelden') }}</button>
    </form>
    @if(config('clubunio-security.enabled'))
        <a href="{{ route('clubunio.security.show', ['mode' => 'portal']) }}">{{ trans('account-security.strong_login') }}</a>
    @endif
    @if(config('clubunio-support.enabled'))
        <a href="{{ route('clubunio.support.login-help') }}">{{ trans('support-intake.title') }}</a>
    @endif
    <a class="admin-link" href="{{ url('/login') }}">{{ __('Verwaltung') }}</a>
</section>
@endsection
