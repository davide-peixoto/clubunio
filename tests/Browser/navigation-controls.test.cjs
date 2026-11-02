const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

class Element extends EventTarget {
    constructor() {
        super();
        this.dataset = {};
        this.attributes = new Map();
        this.hidden = false;
        this.value = '';
        this.classes = new Set();
        this.classList = {
            add: name => this.classes.add(name), remove: name => this.classes.delete(name),
            toggle: (name, active) => active ? this.classes.add(name) : this.classes.delete(name),
        };
    }
    setAttribute(name, value) { this.attributes.set(name, value); }
    getAttribute(name) { return this.attributes.get(name); }
    focus() { this.focused = true; }
    setPointerCapture(id) { this.capture = id; }
    hasPointerCapture(id) { return this.capture === id; }
    releasePointerCapture() { this.capture = null; }
}

function fixture(saved = {}, desktop = true) {
    const search = new Element(), toggle = new Element(), panel = new Element(), handle = new Element();
    const empty = new Element(), control = new Element(), sidebar = new Element();
    panel.hidden = true;
    control.dataset.user = '42';
    const style = new Map();
    const root = new Element();
    root.style = { setProperty: (name, value) => style.set(name, value) };
    control.closest = () => sidebar;
    control.querySelector = selector => ({
        'input[type=search]': search, '[data-cu-search-toggle]': toggle,
        '#cu-menu-search-panel': panel, '.cu-nav-empty': empty,
    })[selector] ?? null;
    sidebar.querySelector = () => handle;
    sidebar.querySelectorAll = () => [];
    sidebar.getBoundingClientRect = () => ({ width: Number(handle.getAttribute('aria-valuenow')) });
    const document = { readyState: 'complete', documentElement: root, addEventListener() {},
        querySelectorAll: () => [control] };
    const localStorage = { getItem: key => saved[key], setItem: (key, value) => { saved[key] = value; } };
    const context = { window: { matchMedia: () => ({ matches: desktop }) }, document,
        localStorage, getComputedStyle: () => ({ direction: 'ltr' }), Event, CustomEvent, URL };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/clubunio-navigation.js'), 'utf8'), context);
    const emit = (element, type, fields = {}) => {
        const event = Object.assign(new Event(type, { cancelable: true }), fields);
        element.dispatchEvent(event);
        return event;
    };
    return { search, toggle, panel, handle, style, root, saved, emit };
}

test('search opens on demand and Escape clears the filter and returns focus', () => {
    const f = fixture();
    f.emit(f.toggle, 'click');
    assert.equal(f.panel.hidden, false);
    assert.equal(f.toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(f.search.focused, true);
    f.search.value = 'staff';
    f.emit(f.search, 'input');
    f.emit(f.search, 'keydown', { key: 'Escape' });
    assert.equal(f.panel.hidden, true);
    assert.equal(f.search.value, '');
    assert.equal(f.toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(f.toggle.focused, true);
});

test('pointer resizing is bounded, saved per user and cancellation restores the start', () => {
    const f = fixture();
    f.emit(f.handle, 'pointerdown', { button: 0, pointerId: 7, clientX: 280 });
    f.emit(f.handle, 'pointermove', { pointerId: 7, clientX: 350 });
    assert.equal(f.style.get('--sidebar-width'), '350px');
    f.emit(f.handle, 'pointerup', { pointerId: 7 });
    assert.equal(f.saved['clubunio.sidebar.width.42'], '350');
    assert.equal(f.root.classes.has('cu-sidebar-resizing'), false);
    f.emit(f.handle, 'pointerdown', { button: 0, pointerId: 8, clientX: 350 });
    f.emit(f.handle, 'pointermove', { pointerId: 8, clientX: 900 });
    assert.equal(f.style.get('--sidebar-width'), '420px');
    f.emit(f.handle, 'pointercancel', { pointerId: 8 });
    assert.equal(f.style.get('--sidebar-width'), '350px');
});

test('saved width, keyboard bounds, reset and mobile drag protection remain available', () => {
    const f = fixture({ 'clubunio.sidebar.width.42': '320' });
    assert.equal(f.style.get('--sidebar-width'), '320px');
    f.emit(f.handle, 'keydown', { key: 'ArrowLeft' });
    assert.equal(f.style.get('--sidebar-width'), '310px');
    f.emit(f.handle, 'keydown', { key: 'Home' });
    assert.equal(f.style.get('--sidebar-width'), '240px');
    f.emit(f.handle, 'keydown', { key: 'End' });
    assert.equal(f.style.get('--sidebar-width'), '420px');
    f.emit(f.handle, 'dblclick');
    assert.equal(f.style.get('--sidebar-width'), '280px');
    const mobile = fixture({}, false);
    mobile.emit(mobile.handle, 'pointerdown', { button: 0, pointerId: 1, clientX: 280 });
    mobile.emit(mobile.handle, 'pointermove', { pointerId: 1, clientX: 400 });
    assert.equal(mobile.style.get('--sidebar-width'), '280px');
});
