<aside role="status" style="position:sticky;top:0;z-index:9999;background:#fff0bd;color:#302400;padding:12px 18px;display:flex;gap:16px;align-items:center;flex-wrap:wrap;border-bottom:2px solid #a66d00">
    <strong>Simulation als {{ $target->display_name }} · Nur lesen</strong>
    <span>Bis {{ \Carbon\CarbonImmutable::parse($expires)->timezone('Europe/Zurich')->format('H:i') }}</span>
    <form method="post" action="{{ route('clubunio.support.stop') }}">@csrf
        <button type="submit" style="border:1px solid currentColor;padding:6px 12px;background:white;border-radius:4px">Simulation beenden</button>
    </form>
</aside>
