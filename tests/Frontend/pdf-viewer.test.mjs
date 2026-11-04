import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { SourceTextModule, SyntheticModule, createContext } from 'node:vm';

const catalog = JSON.parse(await readFile(new URL('../../lang/parts/views.en.json', import.meta.url), 'utf8'));
function messages(locale) {
    const translate = key => locale === 'en' ? catalog[key] : key;
    return {
        loadingText: translate('Dokument wird geladen…'),
        renderErrorText: translate('Dokument konnte nicht angezeigt werden. Bitte erneut öffnen oder herunterladen.'),
        unavailableText: translate('Dokument ist derzeit nicht verfügbar. Bitte erneut öffnen oder herunterladen.'),
    };
}

test('inline PDF lifecycle uses one read, isolates roots and disposes hidden viewers', async () => {
    const roots = [], requests = [], destroyed = [];
    let changed;
    function root(url, locale = 'de') {
        const controls = new Map();
        for (const name of ['pdf', 'status', 'page', 'pages', 'zoom', 'document', 'previous', 'next', 'zoom-in', 'zoom-out', 'fit-width', 'fit-height', 'custom-zoom']) {
            const control = new EventTarget();
            Object.assign(control, { value: name === 'zoom' ? 'fit' : '', style: {}, clientWidth: 720, clientHeight: 500, hidden: false,
                setAttribute() {}, getContext: () => ({}) });
            controls.set(name, control);
        }
        const result = { dataset: { pdfUrl: url, ...messages(locale) }, isConnected: true, visible: true, controls,
            getClientRects() { return this.visible ? [{}] : []; },
            querySelector(selector) { return controls.get(selector.match(/"([^"]+)"/)[1]); } };
        roots.push(result);
        return result;
    }
    const first = root('https://app.invalid/documents/invoices/1/bexio');
    const document = { documentElement: {}, querySelectorAll: () => roots.filter(r => r.isConnected), addEventListener() {} };
    const context = createContext({ document, location: { href: 'https://app.invalid/invoices/1', origin: 'https://app.invalid' },
        window: { devicePixelRatio: 2 }, AbortController, URL, setTimeout, clearTimeout,
        requestAnimationFrame: callback => { callback(); return 1; },
        MutationObserver: class { constructor(callback) { changed = callback; } observe() {} },
        ResizeObserver: class { observe() {} disconnect() {} } });
    const source = await readFile(new URL('../../public/clubunio/pdf-viewer.js', import.meta.url), 'utf8');
    const library = new SyntheticModule(['getDocument', 'GlobalWorkerOptions'], function () {
        this.setExport('GlobalWorkerOptions', {});
        this.setExport('getDocument', options => {
            requests.push(options);
            const page = { getViewport: ({ scale }) => ({ width: 600 * scale, height: 800 * scale }),
                render: () => ({ promise: options.url.includes('render-error') ? Promise.reject(new Error('internal rendering error')) : Promise.resolve(), cancel() {} }) };
            return { promise: options.url.includes('load-error') ? Promise.reject(new Error('internal load error')) : Promise.resolve({ numPages: 2, getPage: async () => page }),
                destroy() { destroyed.push(options.url); return Promise.resolve(); } };
        });
    }, { context });
    const module = new SourceTextModule(source, { context, initializeImportMeta(meta) { meta.url = 'https://app.invalid/clubunio/pdf-viewer.js'; } });
    await module.link(() => library);
    await module.evaluate();
    const settle = () => new Promise(resolve => setImmediate(resolve));
    await settle();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].disableRange, true);
    assert.equal(requests[0].isEvalSupported, false);
    assert.equal(requests[0].withCredentials, true);
    assert.ok(first.controls.get('pdf').width > 0);
    first.controls.get('next').dispatchEvent(new Event('click'));
    first.controls.get('zoom').value = '2';
    first.controls.get('zoom').dispatchEvent(new Event('change'));
    await settle();
    assert.equal(requests.length, 1, 'Paging and zoom must reuse the current document');
    assert.equal(first.controls.get('page').value, 2);
    first.controls.get('fit-height').dispatchEvent(new Event('click'));
    await settle();
    assert.equal(first.controls.get('pdf').style.height, '476px');
    assert.equal(first.controls.get('pdf').style.width, '357px');
    first.controls.get('fit-width').dispatchEvent(new Event('click'));
    await settle();
    assert.equal(first.controls.get('pdf').style.width, '696px');
    first.controls.get('zoom-in').dispatchEvent(new Event('click'));
    await settle();
    assert.ok(parseFloat(first.controls.get('pdf').style.width) > 696);
    first.controls.get('zoom-out').dispatchEvent(new Event('click'));
    await settle();
    assert.equal(first.controls.get('pdf').style.width, '696px');
    assert.equal(requests.length, 1, 'Fit buttons and incremental zoom never refetch the PDF');
    first.visible = false;
    changed();
    assert.equal(destroyed.length, 1);
    assert.equal(first.controls.get('pdf').width, 0);
    first.visible = true;
    changed();
    await settle();
    assert.equal(requests.length, 2, 'Reopening performs a fresh authorized read');
    assert.equal(first.controls.get('page').value, 1);
    first.dataset.pdfUrl = 'https://app.invalid/documents/invoices/2/local';
    changed();
    await settle();
    assert.equal(destroyed.length, 2);
    assert.equal(requests.length, 3);
    const second = root('https://app.invalid/documents/invoices/3/local');
    changed();
    await settle();
    assert.equal(requests.length, 4);
    assert.equal(second.controls.get('page').value, 1);
    const foreign = root('https://outside.invalid/private.pdf');
    changed();
    await settle();
    assert.equal(requests.length, 4, 'Cross-origin PDF URL must not be requested');
    assert.match(foreign.controls.get('status').textContent, /nicht verfügbar/);
    for (const item of roots) item.isConnected = false;
    changed();
    assert.equal(destroyed.length, 4);
    for (const locale of ['de', 'en']) {
        const loading = root('https://app.invalid/load-error', locale);
        changed();
        assert.equal(loading.controls.get('status').textContent, messages(locale).loadingText);
        await settle();
        assert.equal(loading.controls.get('status').textContent, messages(locale).unavailableText);
        const broken = root('https://app.invalid/render-error', locale);
        changed();
        await settle();
        assert.equal(broken.controls.get('status').textContent, messages(locale).renderErrorText);
        assert.equal(broken.controls.get('status').hidden, false);
        loading.isConnected = broken.isConnected = false;
        changed();
    }
});

test('image preview fits either dimension, zooms without rereading and releases its source', async () => {
    let changed, resized;
    const reads = [], released = [], drawn = [];
    const controls = new Map();
    const context2d = { save() {}, restore() {}, setTransform() {}, drawImage(...args) { drawn.push(args.slice(1)); } };
    for (const name of ['pdf', 'status', 'page', 'pages', 'zoom', 'document', 'previous', 'next', 'zoom-in', 'zoom-out', 'fit-width', 'fit-height', 'custom-zoom']) {
        const control = new EventTarget();
        Object.assign(control, { value: name === 'zoom' ? 'fit' : '', style: {}, clientWidth: 524, clientHeight: 324,
            setAttribute() {}, getContext: () => context2d });
        controls.set(name, control);
    }
    const root = { dataset: { pdfUrl: '/documents/members/1/open', mediaType: 'image', ...messages('en') }, isConnected: true, visible: true,
        getClientRects() { return this.visible ? [{}] : []; }, querySelector: selector => controls.get(selector.match(/"([^"]+)"/)[1]) };
    const context = createContext({ document: { documentElement: {}, querySelectorAll: () => [root], addEventListener() {} },
        location: { href: 'https://app.invalid/members/1', origin: 'https://app.invalid' }, window: { devicePixelRatio: 2 },
        AbortController, URL, setTimeout: callback => { queueMicrotask(callback); return 1; }, clearTimeout() {},
        requestAnimationFrame: callback => { callback(); return 1; },
        MutationObserver: class { constructor(callback) { changed = callback; } observe() {} },
        ResizeObserver: class { constructor(callback) { resized = callback; } observe() {} disconnect() {} },
        Image: class {
            naturalWidth = 800; naturalHeight = 1000;
            set src(url) { reads.push(url); queueMicrotask(() => this.onload?.()); }
            removeAttribute(name) { released.push(name); }
        },
    });
    const library = new SyntheticModule(['getDocument', 'GlobalWorkerOptions'], function () {
        this.setExport('GlobalWorkerOptions', {});
        this.setExport('getDocument', () => { throw new Error('Image must not use PDF loader'); });
    }, { context });
    const source = await readFile(new URL('../../public/clubunio/pdf-viewer.js', import.meta.url), 'utf8');
    const module = new SourceTextModule(source, { context, initializeImportMeta(meta) { meta.url = 'https://app.invalid/clubunio/pdf-viewer.js'; } });
    await module.link(() => library);
    await module.evaluate();
    const settle = () => new Promise(resolve => setImmediate(resolve));
    await settle();
    assert.equal(controls.get('pdf').style.width, '500px');
    assert.equal(drawn.length, 1);
    controls.get('fit-height').dispatchEvent(new Event('click'));
    await settle();
    assert.equal(controls.get('pdf').style.height, '300px');
    assert.equal(controls.get('pdf').style.width, '240px');
    controls.get('document').clientHeight = 224;
    resized();
    await settle();
    assert.equal(controls.get('pdf').style.height, '200px');
    controls.get('zoom-in').dispatchEvent(new Event('click'));
    await settle();
    assert.equal(controls.get('pdf').style.width, '200px');
    assert.equal(reads.length, 1, 'Zoom and fit reuse already authorized image bytes');
    root.visible = false;
    changed();
    assert.deepEqual(released, ['src']);
    assert.equal(controls.get('pdf').width, 0);
    root.visible = true;
    changed();
    await settle();
    assert.equal(reads.length, 2, 'Reopening does not retain a private image cache');
    root.isConnected = false;
    changed();
    assert.equal(released.length, 2);
});
