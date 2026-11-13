@extends('clubunio.portal.layout')
@section('content')
<h1>{{ __('profile_web.title') }}</h1>
<p><strong>{{ $actor->display_name }}</strong><br>{{ $actor->username }}</p>
@if(session('profile_notice'))<p role="status">{{ session('profile_notice') }}</p>@endif
@if($errors->any())
    <ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
@endif
<section>
    <h2>{{ __('photo.title') }}</h2>
    @if($hasPhoto)
        <img src="{{ route($prefix.'photo', absolute: false) }}" alt="{{ __('photo.title') }}" width="96" height="96" style="object-fit:cover;border-radius:50%">
    @endif
    @if($canUpload)
        <form method="post" action="{{ route($prefix.'upload', absolute: false) }}" enctype="multipart/form-data">
            @csrf
            <label>{{ __('photo.title') }} <input type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>
            <button type="submit">{{ __('profile_web.upload') }}</button>
        </form>
    @endif
</section>
<section>
    <h2>{{ __('profile_web.password') }}</h2>
    <form method="post" action="{{ route($prefix.'password', absolute: false) }}">
        @csrf
        <p><label>{{ __('profile_web.current') }} <input type="password" name="current_password" autocomplete="current-password" required></label></p>
        <p><label>{{ __('profile_web.new') }} <input type="password" name="password" autocomplete="new-password" minlength="12" required></label></p>
        <p><label>{{ __('profile_web.confirm') }} <input type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label></p>
        <button type="submit">{{ __('profile_web.password') }}</button>
    </form>
</section>
@endsection
