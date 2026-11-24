let seen = new WeakSet();
let charts = new WeakSet();
const running = new Set();
const reduced = () => {
    const choice = document.querySelector('.cu-glass-dashboard')?.dataset.cuMotion;
    return choice === 'off' || (choice !== 'full' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
};
const ease = 'cubic-bezier(.22,1,.36,1)';

function animate(node, frames, options) {
    if (reduced() || !node?.animate) return;
    const animation = node.animate(frames, { fill: 'backwards', easing: ease, ...options });
    running.add(animation);
    animation.finished.catch(() => {}).finally(() => running.delete(animation));
    return animation;
}

function count(node) {
    const original = node.textContent.trim();
    const match = original.match(/^(CHF\s*)?([\d'’.,]+)$/);
    if (!match || reduced()) return;
    const value = Number(match[2].replace(/['’,]/g, ''));
    if (!Number.isFinite(value) || value === 0) return;
    const fraction = match[2].includes('.') ? match[2].split('.')[1].length : 0;
    const overlay = document.createElement('span');
    overlay.className = 'cu-count-overlay';
    overlay.setAttribute('aria-hidden', 'true');
    node.dataset.cuCounting = 'true';
    node.append(overlay);
    const started = performance.now();
    function tick(now) {
        if (!node.isConnected || reduced()) { overlay.remove(); delete node.dataset.cuCounting; return; }
        const progress = Math.min(1, (now - started) / 1150);
        const number = value * (1 - Math.pow(1 - progress, 3));
        overlay.textContent = (match[1] || '') + new Intl.NumberFormat('de-CH', { minimumFractionDigits: fraction, maximumFractionDigits: fraction }).format(number);
        if (progress < 1) requestAnimationFrame(tick);
        else { overlay.remove(); delete node.dataset.cuCounting; }
    }
    requestAnimationFrame(tick);
}

let observer;
export function observeMotion() {
    if (typeof window === 'undefined' || reduced() || !('IntersectionObserver' in window)) return;
    observer ||= new IntersectionObserver(entries => {
        let stagger = 0;
        for (const entry of entries) {
            if (!entry.isIntersecting) continue;
            const node = entry.target;
            observer.unobserve(node);
            animate(node, [{ opacity: .15, transform: 'translateY(24px)', filter: 'blur(5px)' }, { opacity: 1, transform: 'translateY(0)', filter: 'blur(0)' }], { duration: 850, delay: Math.min(stagger++ * 65, 390) });
            node.querySelectorAll('.fi-wi-stats-overview-stat-value').forEach(count);
        }
    }, { threshold: .08 });
    document.querySelectorAll('.cu-glass-dashboard .fi-wi-stats-overview-stat, .cu-glass-dashboard .fi-wi-chart .fi-section, .cu-glass-hero aside').forEach(node => {
        if (!seen.has(node)) { seen.add(node); observer.observe(node); }
    });
}

export function enterChart(node) {
    if (!node || typeof window === 'undefined' || charts.has(node) || reduced() || !('IntersectionObserver' in window)) return;
    charts.add(node);
    const observer = new IntersectionObserver(entries => {
        if (!entries.some(e => e.isIntersecting)) return;
        observer.disconnect();
        node.querySelectorAll('.cu-v42-bar-area rect').forEach((bar, i) => {
            const y = Number(bar.getAttribute('y'));
            const h = Number(bar.getAttribute('height'));
            // Keep actual data attributes unchanged; scale around the mark's baseline.
            bar.style.transformOrigin = `center ${y + h}px`;
            animate(bar, [{ transform: 'scaleY(0)' }, { transform: 'scaleY(1)' }], { duration: 1100, delay: Math.min(i * 35, 420) });
        });
        node.querySelectorAll('.cu-v42-ring svg').forEach(ring => animate(ring, [{ clipPath: 'inset(0 100% 0 0)', opacity: .2, transform: 'rotate(-22deg)' }, { clipPath: 'inset(0 0 0 0)', opacity: 1, transform: 'rotate(0deg)' }], { duration: 1300 }));
    }, { threshold: .15 });
    observer.observe(node);
    return () => observer.disconnect();
}

if (typeof window !== 'undefined') {
    document.addEventListener('cu:motion-change', () => {
        if (reduced()) { for (const animation of running) animation.cancel(); return; }
        seen = new WeakSet();
        charts = new WeakSet();
        observeMotion();
        document.querySelectorAll('.cu-v42-chart').forEach(enterChart);
    });
    window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', event => {
        if (event.matches) for (const animation of running) animation.cancel();
    });
}
