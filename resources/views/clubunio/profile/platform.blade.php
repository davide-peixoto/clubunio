@extends('clubunio.platform.layout')
@section('content')
<section class="platform-section">
    <h1>{{ trans('account-security.profile_title') }}</h1>
    @include('clubunio.profile.details')
</section>
@endsection
