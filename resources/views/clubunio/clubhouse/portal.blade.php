@extends('clubunio.portal.layout')
@push('head')
    @livewireStyles
    @filamentStyles
@endpush
@section('content')
    <h1>{{ trans('clubhouse.title') }}</h1>
    <livewire:clubunio.clubhouse-workspace :tenant-id="(int) $tenant->id" guard="portal" />
    @livewire('notifications')
    @filamentScripts
    @livewireScripts
@endsection
