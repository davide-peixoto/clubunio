<x-filament-panels::page>
    @if ($this->glassSelected())
        <link rel="stylesheet" href="{{ asset('css/clubunio-dashboard.css') }}?v={{ filemtime(public_path('css/clubunio-dashboard.css')) }}">
        <section class="cu-glass-dashboard">
            <div data-cu-dashboard-hero wire:ignore data-props="{{ json_encode([
                'eyebrow' => trans('dashboard_design.eyebrow'),
                'heading' => $this->getHeading(),
                'description' => trans('dashboard_design.description'),
                'tenant' => (string) \Filament\Facades\Filament::getTenant()?->name,
                'live' => trans('dashboard_design.live'),
                'hint' => trans('dashboard_design.hint'),
                'motionLabel' => trans('dashboard_design.motion'),
            ], JSON_THROW_ON_ERROR) }}">
                <div class="cu-glass-hero">
                    <div><p>{{ trans('dashboard_design.eyebrow') }}</p><h1>{{ $this->getHeading() }}</h1><p>{{ trans('dashboard_design.description') }}</p></div>
                    <aside><strong>{{ \Filament\Facades\Filament::getTenant()?->name }}</strong><span>{{ trans('dashboard_design.live') }}</span></aside>
                </div>
            </div>
            {{ $this->content }}
        </section>
        <script src="{{ asset('js/clubunio-dashboard.js') }}?v={{ filemtime(public_path('js/clubunio-dashboard.js')) }}" defer></script>
    @else
        {{ $this->content }}
    @endif
</x-filament-panels::page>
