const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function planner() {
    const context = vm.createContext({});
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/js/pitch-planning.js'), 'utf8'), context);
    const state = context.clubunioPitchPlanner();
    const requests = [];
    state.$wire = { requestSlot: (...args) => { requests.push(args); return Promise.resolve(); } };
    return { state, requests };
}

function event(minute, pointerType = 'mouse') {
    return {
        pointerType, button: 0, pointerId: 1, clientY: 100 + minute * 1.5 + 22,
        target: { closest: () => ({ dataset: { pitchMinute: minute } }) },
        currentTarget: {
            getBoundingClientRect: () => ({ top: 100 }),
            setPointerCapture() {}, hasPointerCapture: () => true, releasePointerCapture() {},
        },
        preventDefault() {},
    };
}

for (const [anchor, end] of [[750, 810], [810, 750]]) {
    test(`pointer range ${anchor} to ${end} requests the selected interval once`, async () => {
        const { state, requests } = planner();
        state.startRange(event(anchor), 5, '2026-11-19');
        state.extendRange(event(end));
        assert.equal(requests.length, 0);
        assert.equal(state.rangeText(':start–:end · :minutes min'), '12:30–14:00 · 90 min');
        state.finishRange(event(end));
        state.requestRange(5, '2026-11-19', 750);
        assert.deepEqual(requests, [[5, '2026-11-19', '12:30', 90]]);
        await Promise.resolve();
        assert.equal(state.busy, false);
        assert.equal(state.selection, null);
    });
}

test('simple click keeps the existing duration and cancellation sends nothing', () => {
    const first = planner();
    first.state.startRange(event(750), 5, '2026-11-19');
    first.state.finishRange(event(750));
    assert.deepEqual(first.requests, [[5, '2026-11-19', '12:30', 120]]);
    const second = planner();
    second.state.startRange(event(750), 5, '2026-11-19');
    second.state.cancelRange();
    second.state.finishRange(event(810));
    second.state.activateSlot({ detail: 1 }, 5, '2026-11-19', 810);
    assert.deepEqual(second.requests, []);
});

test('keyboard and touch taps still work after a cancelled mouse selection', async () => {
    const { state, requests } = planner();
    state.startRange(event(750), 5, '2026-11-19');
    state.cancelRange();
    await state.activateSlot({ detail: 0 }, 5, '2026-11-19', 750);
    state.startRange(event(810, 'touch'), 5, '2026-11-19');
    await state.activateSlot({ detail: 1 }, 5, '2026-11-19', 810);
    assert.deepEqual(requests, [[5, '2026-11-19', '12:30', 120], [5, '2026-11-19', '13:30', 120]]);
});

test('touch scrolling and existing booking drags never start a range', () => {
    const { state, requests } = planner();
    state.startRange(event(750, 'touch'), 5, '2026-11-19');
    assert.equal(state.selection, null);
    state.dragKey = 'training:7';
    state.startRange(event(750), 5, '2026-11-19');
    assert.equal(state.selection, null);
    assert.deepEqual(requests, []);
});

test('range stays inside the displayed day and field', () => {
    const { state, requests } = planner();
    state.startRange(event(1380), 5, '2026-11-19');
    state.extendRange(event(2000));
    assert.equal(state.rangeText(':start–:end · :minutes min'), '23:00–24:00 · 60 min');
    state.finishRange(event(2000));
    assert.deepEqual(requests, [[5, '2026-11-19', '23:00', 60]]);
});
