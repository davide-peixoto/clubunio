import React, { Component } from 'react';
import { createRoot } from 'react-dom/client';
import FacilityPlanner from './facility-planner-view';

const roots = new WeakMap();
class FallbackBoundary extends Component {
    state = { failed: false };
    static getDerivedStateFromError() { return { failed: true }; }
    componentDidCatch() { this.props.record.failed = true; this.props.node.classList.remove('cu-facility-ready'); }
    componentDidMount() { this.props.node.classList.add('cu-facility-ready'); }
    render() { return this.state.failed ? null : this.props.children; }
}
function mount() {
    document.querySelectorAll('[data-cu-facility-planner]').forEach(node => {
        const canvas = node.querySelector('[data-cu-facility-canvas]');
        if (!canvas || !window.Livewire) return;
        const prior = roots.get(canvas), payload = node.dataset.props;
        if (prior?.payload === payload) {
            if (!prior.failed && !node.classList.contains('cu-facility-ready')) node.classList.add('cu-facility-ready');
            return;
        }
        try {
            const data = JSON.parse(payload), root = prior?.root || createRoot(canvas);
            const record = prior || { root, payload, failed: false };
            const invoke = (method, ...args) => {
                if (!['editEntry', 'requestSlot', 'proposeMove', 'openDay'].includes(method)) return Promise.reject();
                const host = node.closest('[wire\\:id]');
                const component = host && window.Livewire.find(host.getAttribute('wire:id'));
                return component ? component.$call(method, ...args) : Promise.reject();
            };
            root.render(<FallbackBoundary node={node} record={record}><FacilityPlanner data={data} invoke={invoke} /></FallbackBoundary>);
            record.payload = payload;
            roots.set(canvas, record);
            if (!record.failed && !node.classList.contains('cu-facility-ready')) node.classList.add('cu-facility-ready');
        } catch { node.classList.remove('cu-facility-ready'); }
    });
}
mount();
document.addEventListener('livewire:init', mount);
document.addEventListener('livewire:navigated', mount);
let scheduled = false;
new MutationObserver(records => {
    if (scheduled || !records.some(r => (r.type === 'attributes' && r.target.hasAttribute('data-cu-facility-planner')) || [...r.addedNodes].some(n => n.nodeType === 1))) return;
    scheduled = true; queueMicrotask(() => { scheduled = false; mount(); });
}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-props', 'class'] });
