<!doctype html>
<html lang="{{ app()->getLocale() }}">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ trans('support-intake.title') }} · ClubUnio</title>
<link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-support.css') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-support-workspace.css') }}?v=1">
<header class="portal-header">@include('clubunio.brand.logo')</header>
<main class="cu-support cu-support-workspace">
    <header class="cs-heading">
    <h1>{{ trans('support-intake.title') }}</h1>
    <a class="cs-text-link" href="{{ route('clubunio.support.help') }}">@svg('heroicon-o-arrow-left') {{ trans('support.title') }}</a>
    </header>
    @if(session('support_received'))
        <p role="status">{{ trans('support-intake.received') }}</p>
    @else
        <p>{{ trans('support-intake.warning') }}</p>
        @if($errors->any())<p role="alert">{{ trans('support.failed') }}</p>@endif
        <form method="post" action="{{ route('clubunio.support.login-help') }}">
            @csrf
            <label>{{ trans('support-intake.email') }}<input name="email" type="email" autocomplete="email" maxlength="254" value="{{ old('email') }}" required></label>
            <label>{{ trans('support.body') }}<textarea name="body" maxlength="2000" required>{{ old('body') }}</textarea></label>
            <button>{{ trans('support-intake.send') }}</button>
        </form>
    @endif
</main>
</html>
