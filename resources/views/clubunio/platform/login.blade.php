@extends('clubunio.platform.layout')
@section('content')
<section class="platform-login"><h1>Plattformverwaltung</h1><form method="post" action="{{ route('clubunio.platform.login') }}">@csrf
<label>Benutzername<input name="username" autocomplete="username" required maxlength="255" value="{{ old('username') }}"></label>
<label>Passwort<input name="password" type="password" autocomplete="current-password" required maxlength="255"></label>
<button class="primary">@svg('heroicon-o-lock-closed') Anmelden</button></form></section>
@endsection
