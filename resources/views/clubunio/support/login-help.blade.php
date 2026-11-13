<!doctype html>
<html lang="{{ app()->getLocale() }}">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ trans('support-intake.title') }} · ClubUnio</title>
<link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-support.css') }}">
<main class="cu-support">
    <h1>{{ trans('support-intake.title') }}</h1>
    @if(session('support_received'))
        <p role="status">{{ trans('support-intake.received') }}</p>
    @else
        <p>{{ trans('support-intake.warning') }}</p>
        @if($errors->any())<p role="alert">{{ trans('support.failed') }}</p>@endif
        <form method="post">
            @csrf
            <label>{{ trans('support-intake.email') }}<input name="email" type="email" autocomplete="email" maxlength="254" required></label>
            <label>{{ trans('support.body') }}<textarea name="body" maxlength="2000" required></textarea></label>
            <button>{{ trans('support-intake.send') }}</button>
        </form>
    @endif
</main>
</html>
