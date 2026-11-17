@extends('clubunio.portal.layout')
@section('content')
<section>
    <h1>{{ trans('account-security.profile_title') }}</h1>
    @include('clubunio.profile.details')
</section>
@endsection
