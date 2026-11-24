import React, { useEffect, useRef, useState } from 'react';
import { PX, timeLabel, slotAt, selectionRange, layoutEvents, filterEvents } from './facility-layout.mjs';

export default function FacilityPlanner({ data, invoke }) {
    const { text: t, fields, days, sources, statuses, mode, canRequest, canManage } = data;
    const [field, setField] = useState('all'), [source, setSource] = useState('all'), [status, setStatus] = useState('all');
    const [conflicts, setConflicts] = useState(false), [selection, setSelection] = useState(null), [busy, setBusy] = useState(false), [error, setError] = useState('');
    const [layout, setLayout] = useState(() => typeof window !== 'undefined' && window.matchMedia('(max-width: 600px)').matches ? 'agenda' : 'grid');
    const [dragPreview, setDragPreview] = useState(null);
    const scroll = useRef(null), range = useRef(null), edge = useRef(null), frame = useRef(null), dragKey = useRef(null), suppressClick = useRef(0);
    const pointerDrag = useRef(null);
    const effectiveField = mode === 'week' && (field === 'all' || !fields.some(f => String(f.id) === field)) ? String(fields[0]?.id) : field;
    const filters = { field: effectiveField, source, status, conflicts };
    const filteredDays = days.map(day => ({ ...day, events: filterEvents(day.events, filters) }));
    const unique = [...new Map(filteredDays.flatMap(d => d.events).map(e => [e.key, e])).values()];
    const selectedField = fields.find(f => String(f.id) === effectiveField);
    const columns = mode === 'day'
        ? fields.filter(f => effectiveField === 'all' || String(f.id) === effectiveField).map(f => ({ id: `field-${f.id}`, field: f, date: days[0].date, label: f.name, events: filteredDays[0].events.filter(e => String(e.field_id) === String(f.id)) }))
        : filteredDays.map(day => ({ id: day.date, date: day.date, field: selectedField, label: day.label, today: day.today, events: day.events }));
    const format = (template, values) => Object.entries(values).reduce((s, [key, value]) => s.replaceAll(`:${key}`, value), template);
    const act = async (method, ...args) => {
        if (busy) return;
        setBusy(true); setError('');
        try { await invoke(method, ...args); } catch { setError(t.failed); } finally { setBusy(false); }
    };
    const stop = () => { cancelAnimationFrame(frame.current); frame.current = null; edge.current = null; };
    const cancel = () => { stop(); range.current = null; setSelection(null); dragKey.current = null; pointerDrag.current = null; setDragPreview(null); };
    const moveTarget = (x, y) => {
        const viewport = scroll.current?.getBoundingClientRect();
        if (!viewport || y <= viewport.top + 60 || y >= viewport.bottom || x < viewport.left + 54 || x >= viewport.right) return null;
        const column = document.elementsFromPoint(x, y).map(el => el.closest('[data-cu-drop-field]')).find(Boolean);
        if (!column || !scroll.current.contains(column)) return null;
        return { field: Number(column.dataset.cuDropField), date: column.dataset.cuDropDate, minute: slotAt(y, column.getBoundingClientRect().top) };
    };
    const beginEntryMove = (e, event, timeline) => {
        if (!timeline || !canManage || !event.editable || busy || e.pointerType === 'touch' || e.button !== 0) return;
        pointerDrag.current = { key: event.key, pointer: e.pointerId, x: e.clientX, y: e.clientY, moved: false, title: event.title };
        e.currentTarget.setPointerCapture(e.pointerId);
        e.stopPropagation();
    };
    const extendEntryMove = e => {
        const drag = pointerDrag.current;
        if (!drag || drag.pointer !== e.pointerId) return;
        if (!drag.moved && Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 7) return;
        drag.moved = true; dragKey.current = drag.key;
        e.preventDefault(); e.stopPropagation(); autoScroll(e);
        const target = moveTarget(e.clientX, e.clientY);
        setDragPreview({ x: e.clientX, y: e.clientY, title: drag.title, time: target ? timeLabel(target.minute) : '' });
    };
    const finishEntryMove = e => {
        const drag = pointerDrag.current;
        if (!drag || drag.pointer !== e.pointerId) return;
        pointerDrag.current = null; dragKey.current = null; stop(); setDragPreview(null);
        if (!drag.moved) return;
        e.preventDefault(); e.stopPropagation(); suppressClick.current = performance.now() + 350;
        const target = moveTarget(e.clientX, e.clientY);
        if (target) act('proposeMove', drag.key, target.field, target.date, timeLabel(target.minute));
    };
    const updateRange = (column, y) => {
        if (!range.current) return;
        const end = slotAt(y, column.getBoundingClientRect().top);
        if (end !== range.current.end) {
            range.current = { ...range.current, end, moved: true };
            setSelection({ ...range.current });
        }
    };
    const autoScroll = (event, column = null) => {
        edge.current = { x: event.clientX, y: event.clientY, column };
        if (frame.current) return;
        const tick = () => {
            const el = scroll.current, point = edge.current;
            if (!el || !point || (!range.current && !dragKey.current)) { stop(); return; }
            const rect = el.getBoundingClientRect();
            const speed = (p, lo, hi) => p < lo + 44 ? -Math.min(12, (lo + 44 - p) / 3) : p > hi - 44 ? Math.min(12, (p - hi + 44) / 3) : 0;
            el.scrollTop += speed(point.y, rect.top + 54, rect.bottom);
            if (dragKey.current) el.scrollLeft += speed(point.x, rect.left, rect.right);
            if (range.current && point.column) updateRange(point.column, point.y);
            frame.current = requestAnimationFrame(tick);
        };
        frame.current = requestAnimationFrame(tick);
    };
    useEffect(() => {
        const abort = event => { if (event.type !== 'keydown' || event.key === 'Escape') cancel(); };
        window.addEventListener('keydown', abort); window.addEventListener('blur', abort);
        document.addEventListener('visibilitychange', abort);
        return () => { stop(); window.removeEventListener('keydown', abort); window.removeEventListener('blur', abort); document.removeEventListener('visibilitychange', abort); };
    }, []);
    useEffect(() => {
        cancel();
        if (scroll.current) {
            const starts = days.flatMap(d => d.events).map(e => e.minute).filter(m => m >= 360);
            scroll.current.scrollTop = (starts.length ? Math.max(360, Math.min(1020, Math.min(...starts) - 60)) : 480) * PX;
        }
    }, [data.date, mode, layout]);

    const startRange = (event, column) => {
        if (!canRequest || busy || !column.field || event.pointerType === 'touch' || event.button !== 0 || !event.target.closest('[data-cu-slot]')) return;
        const minute = slotAt(event.clientY, event.currentTarget.getBoundingClientRect().top);
        range.current = { id: column.id, field: column.field.id, date: column.date, anchor: minute, end: minute, moved: false, pointer: event.pointerId };
        setSelection(range.current);
        event.currentTarget.setPointerCapture(event.pointerId);
    };
    const finishRange = event => {
        const r = range.current;
        if (!r || event.pointerId !== r.pointer) return;
        stop(); range.current = null; setSelection(null);
        suppressClick.current = performance.now() + 350;
        const span = selectionRange(r.anchor, r.end);
        act('requestSlot', r.field, r.date, timeLabel(span.start), r.moved ? span.duration : 120);
    };
    const openSlot = (column, minute) => {
        if (performance.now() < suppressClick.current) { suppressClick.current = 0; return; }
        if (canRequest && column.field) act('requestSlot', column.field.id, column.date, timeLabel(minute), 120);
    };
    const entry = (event, timeline = false) => {
        const facility = fields.find(f => String(f.id) === String(event.field_id));
        const area = facility?.areas?.find(a => String(a.id) === String(event.area_id));
        const summary = `${event.time_label} · ${event.title} · ${facility?.name || ''} · ${area?.name || t.whole} · ${statuses[event.status] || event.status}${event.conflicts ? ` · ${t.conflicts}: ${event.conflicts}` : ''}`;
        const position = timeline ? { top: event.minute * PX, height: Math.max(46, (event.until - event.minute) * PX - 4), left: `calc(${100 * event.lane / event.lanes}% + 3px)`, width: `calc(${100 / event.lanes}% - 6px)` } : {};
        return <button type="button" key={event.key} disabled={busy} className={`cu-facility-entry ${timeline ? 'is-timeline' : ''} ${timeline && canManage && event.editable ? 'is-movable' : ''} status-${event.status} ${event.conflicts ? 'has-conflict' : ''}`} style={position}
            title={summary} aria-label={format(t.open, { title: summary })} draggable={false}
            onPointerDown={e => beginEntryMove(e, event, timeline)} onPointerMove={extendEntryMove} onPointerUp={finishEntryMove} onPointerCancel={cancel}
            onClick={() => { if (performance.now() < suppressClick.current) { suppressClick.current = 0; return; } act('editEntry', event.key); }}
            onDragStart={e => e.preventDefault()}
            onDragEnd={() => { dragKey.current = null; stop(); }}>
            <span className="cu-facility-entry-time">{event.time_label}</span><strong>{event.title}</strong><span>{facility?.name}{event.area_id ? ` · ${area?.name || t.whole}` : ''}</span>
            <span className="cu-facility-entry-meta"><i>{sources[event.source]}</i><b>{statuses[event.status]}</b>{event.conflicts > 0 && <em>{t.conflicts} · {event.conflicts}</em>}</span>
        </button>;
    };
    return <div className="cu-facility" aria-busy={busy}>
        <div className="cu-facility-overview">{(data.overview || [[t.bookings, unique.length], [t.pending, unique.filter(e => e.status === 'pending').length], [t.conflicts, unique.filter(e => e.conflicts > 0).length]]).map(([label, value]) => <div key={label}><small>{label}</small><strong>{value}</strong></div>)}</div>
        <div className="cu-facility-filters">
            <label>{t.facility}<select value={effectiveField} onChange={e => { cancel(); setField(e.target.value); }}>{mode !== 'week' && <option value="all">{t.all_facilities}</option>}{fields.map(f => <option key={f.id} value={f.id}>{f.name}</option>)}</select></label>
            <label>{t.source}<select value={source} onChange={e => { cancel(); setSource(e.target.value); }}><option value="all">{t.all_sources}</option>{Object.entries(sources).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
            <label>{t.status}<select value={status} onChange={e => { cancel(); setStatus(e.target.value); }}><option value="all">{t.all_statuses}</option>{Object.entries(statuses).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
            <label className="cu-facility-check"><input type="checkbox" checked={conflicts} onChange={e => { cancel(); setConflicts(e.target.checked); }} />{t.conflicts_only}</label>
        </div>
        {mode !== 'month' && <div className="cu-facility-layout" role="group" aria-label={t.presentation}>{['grid', 'agenda'].map(value => <button type="button" key={value} aria-pressed={layout === value} onClick={() => { cancel(); setLayout(value); }}>{value === 'grid' ? t.schedule : t.list}</button>)}</div>}
        <p className="cu-facility-hint">{canRequest ? layout === 'agenda' && mode !== 'month' ? t.agenda_hint : t.hint : t.readonly}<span>{canRequest ? t.touch_hint : ''}</span></p>
        {error && <p role="alert" className="cu-facility-error">{error}</p>}
        <div className="cu-facility-message" role="status">{busy ? t.loading : ''}</div>
        {dragPreview && <div className="cu-facility-drag-preview" aria-hidden="true" style={{ left: Math.min(dragPreview.x + 15, window.innerWidth - 190), top: dragPreview.y + 15 }}>{dragPreview.title}<strong>{dragPreview.time}</strong></div>}
        {mode === 'month' ? <div className="cu-facility-month">{filteredDays.map(day => <section key={day.date} className={day.today ? 'is-today' : ''}>
            <button type="button" disabled={busy} className="cu-facility-date" onClick={() => act('openDay', day.date)}>{day.label}</button>
            {day.events.length ? day.events.map(e => entry(e)) : <p>{t.empty}</p>}
        </section>)}</div> : layout === 'agenda' ? <div className="cu-facility-agenda">{filteredDays.map(day => <section key={day.date}><h2>{day.label}</h2>{day.events.length ? [...day.events].sort((a, b) => a.minute - b.minute).map(e => entry(e)) : <p>{t.empty}</p>}</section>)}</div> : <div ref={scroll} className="cu-facility-scroll" tabIndex="0" role="region" aria-label={t.scroll}>
            <div className="cu-facility-grid" style={{ gridTemplateColumns: `54px repeat(${columns.length}, minmax(${mode === 'week' ? 190 : 250}px,1fr))` }}>
                <div className="cu-facility-corner">Europe/Zurich</div>{columns.map(c => <div key={c.id} className={`cu-facility-column-head ${c.today ? 'is-today' : ''}`}><strong>{c.label}</strong><small>{mode === 'week' ? c.field?.name : c.field?.available ? t.schedule : t.unavailable}</small></div>)}
                <div className="cu-facility-hours">{Array.from({ length: 24 }, (_, i) => <span key={i} style={{ top: i * 100 }}>{timeLabel(i * 60)}</span>)}</div>
                {columns.map(c => <div key={c.id} data-cu-drop-field={c.field?.id} data-cu-drop-date={c.date} className={`cu-facility-column ${c.field?.available === false ? 'is-unavailable' : ''}`}
                    onPointerDown={e => startRange(e, c)} onPointerMove={e => { if (range.current?.pointer === e.pointerId) { updateRange(e.currentTarget, e.clientY); autoScroll(e, e.currentTarget); } }}
                    onPointerUp={finishRange} onPointerCancel={cancel} onLostPointerCapture={() => { if (range.current) cancel(); }}
                    onDragOver={e => { if (dragKey.current && canManage) { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; autoScroll(e); } }}
                    onDrop={e => { e.preventDefault(); const key = dragKey.current; dragKey.current = null; stop(); if (!key || !c.field || !canManage) return; suppressClick.current = performance.now() + 350; const minute = slotAt(e.clientY, e.currentTarget.getBoundingClientRect().top); act('proposeMove', key, c.field.id, c.date, timeLabel(minute)); }}>
                    {Array.from({ length: 48 }, (_, i) => canRequest ? <button data-cu-slot key={i} type="button" disabled={busy} className="cu-facility-slot" style={{ top: i * 50 }} aria-label={format(t.slot, { field: c.field?.name || '', date: c.date, time: timeLabel(i * 30) })} onClick={() => openSlot(c, i * 30)} /> : <div key={i} className="cu-facility-slot" style={{ top: i * 50 }} />)}
                    {selection?.id === c.id && <div className="cu-facility-selection" role="status" style={{ top: selectionRange(selection.anchor, selection.end).start * PX, height: selectionRange(selection.anchor, selection.end).duration * PX }}>{t.selection}<br />{timeLabel(selectionRange(selection.anchor, selection.end).start)}–{timeLabel(selectionRange(selection.anchor, selection.end).end)}</div>}
                    {layoutEvents(c.events).map(e => entry(e, true))}
                </div>)}
            </div>
        </div>}
        <p className="cu-facility-footnote">{t.available_hint}</p>
    </div>;
}
