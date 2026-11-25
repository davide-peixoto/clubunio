// Opt-in DEV diagnostics, loaded only by the authenticated dashboard's ui_perf=1 view.
(() => {
    if (location.hostname !== 'dev.clubunio.ch') return;
    const node = document.createElement('output');
    node.hidden = true;
    node.dataset.cuUiPerformance = '';
    document.body.append(node);
    let lcp = 0;
    try { new PerformanceObserver(list => { lcp = list.getEntries().at(-1)?.startTime || lcp; publish(); }).observe({ type: 'largest-contentful-paint', buffered: true }); } catch { /* Optional metric. */ }
    function publish() {
        const nav = performance.getEntriesByType('navigation')[0];
        node.textContent = JSON.stringify({
            ttfb: nav ? nav.responseStart - nav.requestStart : null,
            dom: nav?.domContentLoadedEventEnd, load: nav?.loadEventEnd,
            paint: performance.getEntriesByType('paint').map(e => ({ name: e.name, ms: e.startTime })), lcp,
            assets: performance.getEntriesByType('resource').filter(e => /\/js\/(?:react-ui\/|clubunio-(?:dashboard|react-navigation|facility-planner))/.test(e.name))
                .map(e => ({ path: new URL(e.name).pathname, bytes: e.transferSize, encoded: e.encodedBodySize, decoded: e.decodedBodySize, ms: e.duration })),
            chartsReady: document.querySelectorAll('.cu-chart-ready').length,
            chartsTotal: document.querySelectorAll('[data-cu-dashboard-chart]').length,
        });
    }
    new PerformanceObserver(publish).observe({ type: 'resource', buffered: true });
    addEventListener('load', publish);
    setInterval(publish, 1000);
    publish();
})();
