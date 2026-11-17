@extends('clubunio.platform.layout')
@push('head')
<link rel="stylesheet" href="{{ asset('css/clubunio-profile.css') }}?v=blue-v2">
@endpush
@section('content')
<section>
    <header class="platform-heading"><h1>{{ trans('account-security.profile_title') }}</h1></header>
    @include('clubunio.profile.details')
</section>
@endsection
