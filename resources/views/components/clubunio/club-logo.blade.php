@props(['url' => null])
<span class="clubunio-club-logo" x-data="{ failed: false }">
    @if ($url)
        <img src="{{ $url }}" alt="" width="36" height="36" loading="lazy" decoding="async" referrerpolicy="no-referrer" x-show="!failed" x-on:error="failed = true" x-on:load="failed = false">
        <span x-cloak x-show="failed" aria-label="Kein Logo verfügbar">–</span>
    @else
        <span aria-label="Kein Logo verfügbar">–</span>
    @endif
</span>
