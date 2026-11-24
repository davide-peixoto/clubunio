import React, { useEffect, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { menuMatches } from './navigation-search.mjs';

function Navigation({ items, labels }) {
    const [open, setOpen] = useState(false), [query, setQuery] = useState(''), [active, setActive] = useState(0);
    const dialog = useRef(), input = useRef(), trigger = useRef();
    const matches = menuMatches(items, query);
    useEffect(() => {
        const shortcut = event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && !event.repeat) {
                event.preventDefault(); setOpen(value => !value);
            }
        };
        document.addEventListener('keydown', shortcut);
        return () => document.removeEventListener('keydown', shortcut);
    }, []);
    useEffect(() => {
        if (open) { setQuery(''); setActive(0); dialog.current.showModal(); input.current.focus(); }
        else if (dialog.current.open) { dialog.current.close(); trigger.current.focus(); }
    }, [open]);
    useEffect(() => { dialog.current?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' }); }, [active]);
    const keyDown = event => {
        if (event.key === 'Escape') {
            event.preventDefault(); setOpen(false);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault(); setActive(value => matches.length ? (value + (event.key === 'ArrowDown' ? 1 : -1) + matches.length) % matches.length : 0);
        } else if (event.key === 'Enter' && matches[active]) {
            event.preventDefault(); window.location.assign(matches[active].url);
        }
    };
    return <>
        <button type="button" className="cu-command-trigger" ref={trigger} onClick={() => setOpen(true)} aria-label={labels.open} aria-haspopup="dialog">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><span>{labels.open}</span><kbd>⌘ / Ctrl K</kbd>
        </button>
        <dialog className="cu-command-dialog" ref={dialog} aria-labelledby="cu-command-title" onCancel={() => setOpen(false)} onClick={event => { if (event.target === dialog.current) setOpen(false); }}>
            <header><h2 id="cu-command-title">{labels.title}</h2><button type="button" aria-label={labels.close} onClick={() => setOpen(false)}>×</button></header>
            <input ref={input} type="search" role="combobox" aria-label={labels.open} aria-controls="cu-command-results" aria-expanded={open} aria-autocomplete="list" aria-activedescendant={matches.length ? `cu-command-${active}` : undefined} placeholder={labels.search} value={query} onChange={event => { setQuery(event.target.value); setActive(0); }} onKeyDown={keyDown}/>
            <ul id="cu-command-results" role="listbox" aria-label={labels.open}>
                {matches.map((item, index) => <li key={`${item.url}-${index}`} role="presentation"><a role="option" id={`cu-command-${index}`} aria-selected={index === active} tabIndex={-1} href={item.url} onMouseMove={() => setActive(index)}><span><strong>{item.label}</strong><small>{item.group}</small></span><span aria-hidden="true">↗</span></a></li>)}
            </ul>
            {!matches.length && <p role="status">{labels.empty}</p>}
            <footer>{labels.help}</footer>
        </dialog>
    </>;
}

const mounted = new WeakSet();
function init() {
    document.querySelectorAll('[data-cu-react-navigation]').forEach(node => {
        if (mounted.has(node)) return;
        try { const props = JSON.parse(node.dataset.props); createRoot(node).render(<Navigation {...props}/>); mounted.add(node); }
        catch (error) { console.error('ClubUnio navigation could not initialize', error); }
    });
}
document.addEventListener('livewire:navigated', init);
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
