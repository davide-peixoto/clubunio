import { test } from 'node:test';
import assert from 'node:assert/strict';
import { civilDate, weekDates } from '../../resources/js/module-layout.mjs';

test('calendar weeks cross month, year and daylight saving boundaries', () => {
    assert.deepEqual(weekDates('2027-01-01'), ['2026-12-28','2026-12-29','2026-12-30','2026-12-31','2027-01-01','2027-01-02','2027-01-03']);
    assert.equal(civilDate('2026-03-29', 1), '2026-03-30');
    assert.equal(civilDate('2026-10-25', -7), '2026-10-18');
    assert.equal(civilDate('2028-02-28', 1), '2028-02-29');
    assert.equal(weekDates('2026-11-23')[0], '2026-11-23');
});
