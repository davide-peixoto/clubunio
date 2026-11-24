import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PX, timeLabel, slotAt, selectionRange, layoutEvents, filterEvents } from '../../resources/js/facility-layout.mjs';

test('civil time slots clamp and range works in both directions', () => {
    assert.equal(slotAt(-100, 0), 0);
    assert.equal(slotAt(5000, 0), 1410);
    assert.equal(slotAt(600 * PX + 200, 200), 600);
    assert.deepEqual(selectionRange(600, 690), { start: 600, end: 720, duration: 120 });
    assert.deepEqual(selectionRange(690, 600), { start: 600, end: 720, duration: 120 });
    assert.equal(timeLabel(1410), '23:30');
    assert.equal(timeLabel(1440), '24:00');
});
test('overlap lanes split only concurrent events and preserve immutable input', () => {
    const input = [{ key: 'a', minute: 600, until: 690 }, { key: 'b', minute: 660, until: 720 }, { key: 'c', minute: 900, until: 960 }];
    const result = layoutEvents(input);
    assert.deepEqual(result.map(e => [e.lane, e.lanes]), [[0, 2], [1, 2], [0, 1]]);
    assert.equal(input[0].lane, undefined);
    assert.deepEqual(layoutEvents([]), []);
});
test('presentation filters use real fields, statuses and server conflict flags', () => {
    const events = [{ key: 'a', field_id: 1, source: 'training', status: 'approved', conflicts: 0 }, { key: 'b', field_id: 2, source: 'request', status: 'pending', conflicts: 2 }];
    assert.deepEqual(filterEvents(events, { field: '2', conflicts: true }).map(e => e.key), ['b']);
    assert.deepEqual(filterEvents(events, { status: 'approved', source: 'request' }), []);
    assert.equal(filterEvents(events).length, 2);
});
