@extends('clubunio.portal.layout')
@section('content')
<section class="portal-login">
    <h1>Mitgliederbereich</h1>
    <form method="post" action="{{ route('clubunio.portal.login') }}">
        @csrf
        <label for="username">Benutzername</label>
        <input id="username" name="username" value="{{ old('username') }}" autocomplete="username" required maxlength="255">
        <label for="password">Passwort</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        @if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
        <button class="primary" type="submit">Anmelden</button>
    </form>
    <a class="admin-link" href="{{ url('/clubunio/login') }}">Verwaltung</a>
</section>
@endsection
