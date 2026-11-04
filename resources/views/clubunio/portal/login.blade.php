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
    <a class="admin-link" href="{{ url('/login') }}">{{ __('Verwaltung') }}</a>
</section>
@endsection
