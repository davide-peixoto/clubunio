@extends('clubunio.portal.layout')
@push('head')
<link rel="stylesheet" href="{{ asset('css/clubunio-profile.css') }}?v=blue-v2">
@endpush
@section('content')
<section>
    <header class="page-heading"><h1>{{ trans('account-security.profile_title') }}</h1></header>
    @include('clubunio.profile.details')
</section>
@endsection
