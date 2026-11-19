<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('keycloak.mobile') }}</title>
</head>
<body>
<main>
    <h1>{{ __('keycloak.mobile') }}</h1>
    @if ($pair)
        <form method="post" action="{{ route('clubunio.verein.oidc.mobile.start') }}">
            @csrf
            <input type="hidden" name="pair" value="{{ $pair }}">
            <label for="confirmation">{{ __('keycloak.confirmation') }}</label>
            <input id="confirmation" name="confirmation" required inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="off">
            <button type="submit">{{ __('keycloak.continue') }}</button>
        </form>
    @else
        <p>{{ __('keycloak.return_to_app') }}</p>
    @endif
</main>
</body>
</html>
