<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ trans('account-security.reset_title') }} · ClubUnio</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clubunio-account-security.css') }}">
</head>
<body>
<main class="cu-security">
    <h1>{{ trans('account-security.reset_title') }}</h1>
    <p>{{ $tenant->name }} · {{ $link->account->display_name }}</p>
    @if($code)
        <p>{{ trans('account-security.reset_handover') }}</p>
        <output>{{ $code }}</output>
    @else
        <p>{{ trans('account-security.reset_effect') }}</p>
        @if($errors->any())
            <p role="alert">{{ $errors->has('operator_password') ? trans('account-security.reset_reauth') : trans('account-security.failed') }}</p>
        @endif
        <form method="post">
            @csrf
            <label>{{ trans('account-security.reset_reason') }}<textarea name="reason" minlength="10" maxlength="500" required></textarea></label>
            <label>{{ trans('account-security.reset_identity') }}<input type="checkbox" name="identity_verified" value="1" required></label>
            <label>{{ trans('account-security.password') }}<input name="operator_password" type="password" autocomplete="current-password" required></label>
            <button class="primary">{{ trans('account-security.reset_title') }}</button>
        </form>
        <a href="{{ route('clubunio.security.show') }}">{{ trans('account-security.reset_verify') }}</a>
    @endif
    <a href="{{ \App\ClubUnio\Resources\TenantAccountResource::getUrl(panel: 'clubunio', tenant: $tenant) }}">{{ trans('account-security.reset_back') }}</a>
</main>
</body>
</html>
