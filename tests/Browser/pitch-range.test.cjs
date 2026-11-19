const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function planner() {
    const frames = new Map();
    let nextFrame = 0;
    const context = vm.createContext({
        requestAnimationFrame: callback => { frames.set(++nextFrame, callback); return nextFrame; },
        cancelAnimationFrame: id => frames.delete(id),
    });
    vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/js/pitch-planning.js'), 'utf8'), context);
    const state = context.clubunioPitchPlanner();
    const requests = [];
    state.$wire = { requestSlot: (...args) => { requests.push(args); return Promise.resolve(); } };
    const frame = time => {
        const pending = [...frames.values()];
        frames.clear();
        pending.forEach(callback => callback(time));
    };
    return { state, requests, frame, frames };
}

function event(minute, pointerType = 'mouse', viewport = null) {
    return {
        pointerType, button: 0, pointerId: 1, clientY: 100 + minute * 1.5 + 22,
        target: { closest: () => ({ dataset: { pitchMinute: minute } }) },
        currentTarget: {
            isConnected: true,
            closest: () => viewport,
            getBoundingClientRect: () => ({ top: 100 - (viewport?.scrollTop || 0) }),
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

function scrollViewport(scrollTop = 900) {
    return {
        scrollTop, scrollHeight: 2160, clientHeight: 400,
        getBoundingClientRect: () => ({ top: 100, bottom: 500 }),
        querySelector: () => ({ getBoundingClientRect: () => ({ height: 60 }) }),
    };
}

for (const direction of ['up', 'down']) {
    test(`stationary pointer at ${direction} edge keeps scrolling and extends the selected duration`, () => {
        const { state, requests, frame, frames } = planner();
        const viewport = scrollViewport();
        const start = event(750, 'mouse', viewport);
        start.clientY -= viewport.scrollTop;
        state.startRange(start, 5, '2026-11-19');
        const edge = { ...start, clientY: direction === 'up' ? 164 : 496 };
        state.extendRange(edge);
        for (let time = 0; time <= 960; time += 16) frame(time);
        assert.ok(direction === 'up' ? viewport.scrollTop < 500 : viewport.scrollTop > 1300);
        assert.equal(state.rangeText(':start–:end · :minutes min'), direction === 'up'
            ? '06:00–13:00 · 420 min' : '12:30–19:30 · 420 min');
        assert.equal(requests.length, 0);
        state.finishRange(edge);
        assert.deepEqual(requests, [[5, '2026-11-19', direction === 'up' ? '06:00' : '12:30', 420]]);
        assert.equal(frames.size, 0);
        const stopped = viewport.scrollTop;
        frame(2000);
        assert.equal(viewport.scrollTop, stopped);
    });
}

test('middle of viewport and short clicks do not scroll, teardown cancels outstanding frames', () => {
    const { state, frame, frames } = planner();
    const viewport = scrollViewport();
    const start = event(750, 'mouse', viewport);
    start.clientY -= viewport.scrollTop;
    state.startRange(start, 5, '2026-11-19');
    state.extendRange({ ...start, clientY: 330 });
    for (let time = 0; time <= 960; time += 16) frame(time);
    assert.equal(viewport.scrollTop, 900);
    state.cancelRange();
    assert.equal(frames.size, 0);
    state.startRange({ ...start, clientY: 496 }, 5, '2026-11-19');
    frame(0); frame(100);
    assert.equal(viewport.scrollTop, 900);
    state.destroy();
    assert.equal(frames.size, 0);
});

test('edge scrolling clamps to the final day slot and stops when the column disconnects', () => {
    const { state, frame, frames } = planner();
    const viewport = scrollViewport(1740);
    const start = event(1380, 'mouse', viewport);
    start.clientY -= viewport.scrollTop;
    state.startRange(start, 5, '2026-11-19');
    state.extendRange({ ...start, clientY: 496 });
    for (let time = 0; time <= 960; time += 16) frame(time);
    assert.equal(viewport.scrollTop, 1760);
    assert.equal(state.rangeText(':start–:end · :minutes min'), '23:00–24:00 · 60 min');
    start.currentTarget.isConnected = false;
    frame(976);
    assert.equal(frames.size, 0);
});
