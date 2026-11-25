import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { observeMotion } from './dashboard-motion';

const roots = new WeakMap();
const chartStates = new WeakMap();
let chartModule;
const chartRenderer = () => chartModule ||= import('./dashboard-charts').catch(error => { chartModule = null; throw error; });

async function renderChart(node) {
    const state = chartStates.get(node);
    if (!state?.visible || state.pending || !node.isConnected) return;
    const canvas = node.querySelector('[data-cu-chart-canvas]');
    if (!canvas || roots.get(canvas)?.payload === node.dataset.props) return;
    state.pending = true;
    try {
        const { default: TemplateChart } = await chartRenderer();
        if (!node.isConnected || !state.visible) return;
        const payload = node.dataset.props;
        const props = JSON.parse(payload);
        const prior = roots.get(canvas);
        const root = prior?.root || createRoot(canvas);
        root.render(<TemplateChart {...props} />);
        roots.set(canvas, { root, payload });
        node.classList.add('cu-chart-ready');
        const fallback = node.querySelector('details');
        if (fallback && !prior) fallback.open = false;
    } catch { /* Keep the complete server-rendered data table available. */ }
    finally { state.pending = false; }
}

const chartObserver = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(entries => {
    for (const entry of entries) {
        const state = chartStates.get(entry.target);
        if (state) { state.visible = entry.isIntersecting; if (state.visible) renderChart(entry.target); }
    }
}, { rootMargin: '80px 0px', threshold: 0 });

function Hero({ eyebrow, heading, description, tenant, live, hint, motionLabel }) {
    const [motion, setMotion] = useState(() => {
        let saved;
        try { saved = localStorage.getItem('clubunio.dashboard.motion'); } catch { /* Optional preference. */ }
        return saved === 'full' || (saved !== 'off' && !matchMedia('(prefers-reduced-motion: reduce)').matches);
    });
    useEffect(() => {
        const panel = document.querySelector('.cu-glass-dashboard');
        if (panel) panel.dataset.cuMotion = motion ? 'full' : 'off';
        document.dispatchEvent(new Event('cu:motion-change'));
    }, [motion]);
    const toggle = () => {
        try { localStorage.setItem('clubunio.dashboard.motion', motion ? 'off' : 'full'); } catch { /* Optional preference. */ }
        setMotion(!motion);
    };
    return <div className="cu-glass-hero">
        <div><p className="cu-glass-eyebrow">{eyebrow}</p><h1 aria-label={heading}>{heading.split(' ').map((word, i) => <React.Fragment key={i}><span className="cu-hero-word" aria-hidden="true" style={{ '--word': i }}>{word}</span>{' '}</React.Fragment>)}</h1><p>{description}</p><small>{hint}</small><button type="button" className="cu-motion-switch" role="switch" aria-checked={motion} onClick={toggle}><span aria-hidden="true" className="cu-motion-track"><i /></span>{motionLabel}</button></div>
        <aside><strong>{tenant}</strong><span><i aria-hidden="true" />{live}</span><div className="cu-glass-orbits" aria-hidden="true"><b>C</b><span>ClubUnio</span></div></aside>
    </div>;
}

function mount() {
    document.querySelectorAll('[data-cu-dashboard-hero]').forEach((node) => {
        if (node.dataset.mounted) return;
        try {
            const props = JSON.parse(node.dataset.props);
            createRoot(node).render(<Hero {...props} />);
            node.dataset.mounted = 'true';
        } catch { /* Keep the server-rendered hero and all existing widgets. */ }
    });
    document.querySelectorAll('[data-cu-dashboard-chart]').forEach((node) => {
        if (!chartStates.has(node)) {
            chartStates.set(node, { visible: !chartObserver, pending: false });
            chartObserver?.observe(node);
        }
        renderChart(node);
    });
    observeMotion();
}
mount();
document.addEventListener('livewire:navigated', mount);
let scheduled = false;
new MutationObserver(records => {
    const relevant = '[data-cu-dashboard-chart],[data-cu-dashboard-hero]';
    if (!records.some(record => record.type === 'attributes' && record.target.matches(relevant)
        || [...record.addedNodes].some(node => node.nodeType === 1 && (node.matches(relevant) || node.querySelector(relevant))))) return;
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; mount(); });
}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-props'] });
