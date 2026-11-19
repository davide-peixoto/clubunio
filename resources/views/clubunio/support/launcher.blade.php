@if(config('clubunio-support.enabled') && !request()->routeIs('clubunio.platform.support*', 'clubunio.support.*'))
    @php
        $supportTenant = $supportTenant ?? \Filament\Facades\Filament::getTenant();
        $supportTarget = ($supportPlatform ?? false)
            ? route('clubunio.platform.support')
            : ($supportTenant ? route('clubunio.support.center', ['club' => $supportTenant->id]) : null);
    @endphp
    @if($supportTarget)
        <script src="{{ asset('js/clubunio-help-widget.js').'?v=3' }}"
            data-close-label="{{ trans('support.close_panel') }}"
            data-support-url="{{ $supportTarget }}"
            data-support-label="{{ trans('support.title') }}"></script>
    @endif
@endif
