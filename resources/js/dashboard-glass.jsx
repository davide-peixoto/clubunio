import React from 'react';
import { createRoot } from 'react-dom/client';

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
}
mount();
document.addEventListener('livewire:navigated', mount);
