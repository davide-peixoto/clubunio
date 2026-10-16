import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { SourceTextModule, SyntheticModule, createContext } from 'node:vm';

test('inline PDF lifecycle uses one read, isolates roots and disposes hidden viewers', async () => {
    const roots = [], requests = [], destroyed = [];
    let changed;
    function root(url) {
        const controls = new Map();
        for (const name of ['pdf', 'status', 'page', 'pages', 'zoom', 'document', 'previous', 'next']) {
            const control = new EventTarget();
            Object.assign(control, { value: name === 'zoom' ? 'fit' : '', style: {}, clientWidth: 720, hidden: false,
                getContext: () => ({}) });
            controls.set(name, control);
        }
        const result = { dataset: { pdfUrl: url }, isConnected: true, visible: true, controls,
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
                render: () => ({ promise: Promise.resolve(), cancel() {} }) };
            return { promise: Promise.resolve({ numPages: 2, getPage: async () => page }),
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
});
