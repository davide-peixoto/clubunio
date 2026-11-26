@if(auth('clubunio')->check() && app(\App\ClubUnio\Services\ReactUi::class)->enabled() && is_file(public_path('js/clubunio-react-navigation.js')))
<link rel="stylesheet" href="{{ asset('css/clubunio-react-navigation.css') }}?v=motion-2">
<script>
(() => {
    // Apply the existing preference before the first incoming-page frame.
    let mode;
    try { mode = localStorage.getItem('clubunio.ui.motion') || localStorage.getItem('clubunio.dashboard.motion'); } catch {}
    const full = mode === 'full' || (mode !== 'reduced' && !matchMedia('(prefers-reduced-motion: reduce)').matches);
    document.documentElement.dataset.cuMotion = full ? 'full' : 'reduced';
})();
</script>
@endif
