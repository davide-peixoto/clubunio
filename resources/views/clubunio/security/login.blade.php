<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ trans('account-security.title') }} · ClubUnio</title>
    <link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clubunio-account-security.css') }}">
</head>
<body>
<main class="cu-security" data-security data-mode="{{ $mode }}" data-pair="{{ $pair }}"
    data-url="{{ url('/account/security') }}" data-token="{{ csrf_token() }}"
    data-error="{{ trans('account-security.failed') }}" data-retry="{{ trans('account-security.retry') }}"
    data-paired="{{ trans('account-security.paired') }}">
    <img src="{{ asset('brand/blue-v1/clubunio-logo-horizontal.svg') }}" width="180" height="50" alt="ClubUnio">
    <h1>{{ trans('account-security.title') }}</h1>
    <p data-result role="status" aria-live="polite" hidden></p>
    @if($mode === 'mobile') <p>{{ trans('account-security.pair_help') }}</p> @endif
    <label @if($mode !== 'mobile') hidden @endif>{{ trans('account-security.pair_code') }}
        <input name="confirmation" autocomplete="off" inputmode="numeric" maxlength="8" pattern="[0-9]{8}">
    </label>
    <div class="cu-security-actions">
        <button type="button" data-passkey>{{ trans('account-security.passkey') }}</button>
    </div>
    <form method="post" data-password>
        <label>{{ trans('account-security.username') }}<input name="username" autocomplete="username" required maxlength="255"></label>
        <label>{{ trans('account-security.password') }}<input name="password" type="password" autocomplete="current-password" required></label>
        <label data-code-label>{{ trans('account-security.code') }}<input name="code" autocomplete="one-time-code" maxlength="40" required></label>
        <button type="submit" class="primary">{{ trans('account-security.authenticator_login') }}</button>
    </form>
    @php($selfSetup = $mode !== 'mobile' && auth($mode === 'platform' ? 'clubunio' : $mode)->check())
    <details data-setup @if($selfSetup || $showSetup) open @endif>
        <summary>{{ trans('account-security.setup') }}</summary>
        <p>{{ trans('account-security.self_setup_help') }}</p>
        <form method="post" data-enrollment>
            <label>{{ trans('account-security.username') }}<input name="username" autocomplete="username" required maxlength="255"></label>
            <label>{{ trans('account-security.password') }}<input name="password" type="password" autocomplete="current-password" required></label>
            <label>{{ trans('account-security.invitation') }}<input name="invitation" autocomplete="off" minlength="40" maxlength="40"></label>
            <div class="cu-security-actions">
                <button type="submit" name="method" value="passkey" class="primary">{{ trans('account-security.setup_passkey') }}</button>
                <button type="submit" name="method" value="totp">{{ trans('account-security.setup_totp') }}</button>
            </div>
            <fieldset data-totp hidden>
                <legend>{{ trans('account-security.setup_totp') }}</legend>
                <p>{{ trans('account-security.secret_help') }}</p>
                <output data-secret></output>
                <label>{{ trans('account-security.first_code') }}<input name="code" autocomplete="one-time-code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}"></label>
                <button type="submit" name="method" value="confirm">{{ trans('account-security.confirm') }}</button>
            </fieldset>
        </form>
    </details>
    <section data-recovery hidden>
        <h2>{{ trans('account-security.recovery') }}</h2>
        <p>{{ trans('account-security.recovery_help') }}</p>
        <ul data-codes></ul>
        <button type="button" data-finish>{{ trans('account-security.done') }}</button>
    </section>
    @if($mode !== 'mobile')
        <a href="{{ match ($mode) { 'portal' => url('/portal/login'), 'platform' => route('clubunio.platform.login'), default => url('/login') } }}">{{ trans('account-security.back') }}</a>
    @endif
</main>
<script type="module" src="{{ asset('js/clubunio-account-security.js') }}?v=1"></script>
</body>
</html>
