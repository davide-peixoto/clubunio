import React from 'react';
import { createRoot } from 'react-dom/client';
import TemplateChart from './dashboard-charts';

const roots = new WeakMap();

function Hero({ eyebrow, heading, description, tenant, live, hint }) {
    return <div className="cu-glass-hero">
        <div><p className="cu-glass-eyebrow">{eyebrow}</p><h2>{heading}</h2><p>{description}</p><small>{hint}</small></div>
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
        const canvas = node.querySelector('[data-cu-chart-canvas]');
        if (!canvas) return;
        const payload = node.dataset.props;
        const prior = roots.get(canvas);
        if (prior?.payload === payload) return;
        try {
            const root = prior?.root || createRoot(canvas);
            root.render(<TemplateChart {...JSON.parse(payload)} />);
            roots.set(canvas, { root, payload });
            node.classList.add('cu-chart-ready');
            const fallback = node.querySelector('details');
            if (fallback && !prior) fallback.open = false;
        } catch { /* The complete server-rendered data table remains available. */ }
    });
}
mount();
document.addEventListener('livewire:navigated', mount);
let scheduled = false;
new MutationObserver(() => {
    if (scheduled) return;
    scheduled = true;
    queueMicrotask(() => { scheduled = false; mount(); });
}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-props'] });
