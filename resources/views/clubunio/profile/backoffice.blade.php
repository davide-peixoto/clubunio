@push('styles')
<link rel="stylesheet" href="{{ asset('css/clubunio-profile.css') }}">
@endpush
<x-filament-panels::page>
    @include('clubunio.profile.details')
</x-filament-panels::page>
