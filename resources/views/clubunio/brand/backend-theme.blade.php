@php($backendBrand = app(\App\ClubUnio\Services\BackendTheme::class)->brand())
@if($backendBrand)
<meta name="clubunio-backend-theme" content="{{ \Filament\Facades\Filament::getTenant()?->id }}">
<style data-cu-backend-theme>
:root {
@foreach(app(\App\ClubUnio\Services\BackendTheme::class)->tokens($backendBrand['color']) as $name => $value)
{{ $name }}: {!! $value !!};
@endforeach
}
</style>
<link rel="stylesheet" href="{{ asset('css/clubunio-backend-theme.css') }}?v=5">
@endif
