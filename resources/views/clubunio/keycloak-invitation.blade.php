<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('keycloak.invitation') }}</title>
</head>
<body>
<main>
    <h1>{{ __('keycloak.invitation') }}</h1>
    <form method="post" action="{{ route('clubunio.verein.oidc.invite.accept') }}">
        @csrf
        <label for="invitation">{{ __('keycloak.code') }}</label>
        <input id="invitation" name="invitation" required minlength="64" maxlength="64" pattern="[0-9a-f]{64}" autocomplete="off">
        <button type="submit">{{ __('keycloak.continue') }}</button>
    </form>
</main>
</body>
</html>
