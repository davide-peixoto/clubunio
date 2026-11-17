@extends('clubunio.platform.layout')
@push('head')
<link rel="stylesheet" href="{{ asset('css/clubunio-profile.css') }}">
@endpush
@section('content')
<section>
    <h1>{{ trans('account-security.profile_title') }}</h1>
    @include('clubunio.profile.details')
</section>
@endsection
