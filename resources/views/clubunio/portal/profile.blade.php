@extends('clubunio.portal.layout')
@push('head')
    <link rel="stylesheet" href="{{ asset('css/clubunio-profile.css') }}?v=1">
@endpush
@section('account-navigation')
    <details class="profile-menu">
        <summary class="icon-button" aria-label="{{ __('profile_web.menu') }}">@svg('heroicon-o-bars-3')</summary>
        <div>
            <a href="{{ $backUrl }}">{{ __('profile_web.back') }}</a>
            <form method="post" action="{{ $logoutUrl }}">@csrf<button type="submit">{{ __('Abmelden') }}</button></form>
        </div>
    </details>
@endsection
@section('content')
<div class="profile-page">
<a class="profile-back" href="{{ $backUrl }}">@svg('heroicon-o-arrow-left') {{ __('profile_web.back') }}</a>
<h1>{{ __('profile_web.title') }}</h1>
<div class="profile-identity">
    @if($hasPhoto)
        <img src="{{ route($prefix.'photo', absolute: false) }}" alt="{{ __('photo.title') }}" width="56" height="56">
    @else
        <span class="profile-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($actor->display_name ?: $actor->username), 0, 1)) }}</span>
    @endif
    <p><strong>{{ $actor->display_name }}</strong><br>{{ $actor->username }}</p>
</div>
@if(session('profile_notice'))<p role="status">{{ session('profile_notice') }}</p>@endif
@if($errors->any())
    <ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
@endif
@if($canUpload)
<section class="profile-section">
    <h2>{{ __('photo.title') }}</h2>
        <form method="post" action="{{ route($prefix.'upload', absolute: false) }}" enctype="multipart/form-data">
            @csrf
            <label>{{ __('photo.title') }} <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>
            <button type="submit">{{ __('profile_web.upload') }}</button>
        </form>
</section>
@endif
<section class="profile-section">
    <h2>{{ __('profile_web.password') }}</h2>
    <form method="post" action="{{ route($prefix.'password', absolute: false) }}">
        @csrf
        <p><label>{{ __('profile_web.current') }} <input type="password" name="current_password" autocomplete="current-password" required></label></p>
        <p><label>{{ __('profile_web.new') }} <input type="password" name="password" autocomplete="new-password" minlength="12" required></label></p>
        <p><label>{{ __('profile_web.confirm') }} <input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label></p>
        <button type="submit">{{ __('profile_web.password') }}</button>
    </form>
</section>
</div>
@endsection
