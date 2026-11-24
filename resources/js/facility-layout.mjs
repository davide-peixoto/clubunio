export const PX = 100 / 60;
export const timeLabel = minute => `${String(Math.floor(minute / 60)).padStart(2, '0')}:${String(minute % 60).padStart(2, '0')}`;
export const slotAt = (clientY, top) => Math.max(0, Math.min(1410, Math.floor((clientY - top) / PX / 30) * 30));
export function selectionRange(a, b) { const start = Math.min(a, b); return { start, end: Math.max(a, b) + 30, duration: Math.abs(a - b) + 30 }; }

// Presentation lanes only. Server conflict and spatial-overlap rules remain authoritative.
export function layoutEvents(events) {
    const rows = events.map(e => ({ ...e })).sort((a, b) => a.minute - b.minute || a.until - b.until || a.key.localeCompare(b.key));
    let lanes = [], group = [], end = 0;
    const finish = () => { group.forEach(e => { e.lanes = Math.max(1, lanes.length); }); group = []; lanes = []; };
    for (const event of rows) {
        if (group.length && event.minute >= end) finish();
        let lane = 0;
        while (lanes[lane] > event.minute) lane++;
        lanes[lane] = Math.max(event.minute + 30, event.until);
        event.lane = lane;
        group.push(event);
        end = Math.max(...lanes);
    }
    finish();
    return rows;
}

export function filterEvents(events, { field = 'all', source = 'all', status = 'all', conflicts = false } = {}) {
    return events.filter(e => (field === 'all' || String(e.field_id) === String(field))
        && (source === 'all' || e.source === source) && (status === 'all' || e.status === status)
        && (!conflicts || e.conflicts > 0));
}
