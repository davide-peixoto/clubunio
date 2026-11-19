<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ trans('account-security.email_recovery') }} · ClubUnio</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clubunio-account-security.css') }}">
</head>
<body>
<main class="cu-security">
    <h1>{{ trans('account-security.email_recovery') }}</h1>
    <p>{{ trans('account-security.email_recovery_help') }}</p>
    <p><code>{{ $code }}</code></p>
    <a href="{{ route('clubunio.security.show', ['mode' => 'portal']) }}">{{ trans('account-security.setup') }}</a>
</main>
</body>
</html>
