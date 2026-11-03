import { getDocument, GlobalWorkerOptions } from '../vendor/pdfjs/pdf.min.js';

GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.js', import.meta.url).href;
const viewers = new Map();

function mount(root) {
    const element = name => root.querySelector(`[data-pdf-control="${name}"]`);
    const canvas = element('pdf'), status = element('status'), input = element('page');
    canvas.hidden = true;
    status.hidden = false;
    status.textContent = 'Dokument wird geladen…';
    input.value = 1;
    input.disabled = true;
    element('previous').disabled = element('next').disabled = true;
    element('pages').textContent = '/ –';
    const listeners = new AbortController();
    let pdf, loadingTask, requestedPage = 1, revision = 0, running = false, task, resize, disposed = false, currentScale = 1;
    const zoomControls = ['zoom', 'zoom-in', 'zoom-out', 'fit-width', 'fit-height'];
    zoomControls.forEach(name => { element(name).disabled = true; });

    async function render() {
        revision++;
        if (running) { task?.cancel(); return; }
        running = true;
        try {
            let renderedRevision;
            do {
                renderedRevision = revision;
                const pageNumber = requestedPage;
                const page = await pdf.getPage(pageNumber);
                if (disposed) return;
                const base = page.getViewport({ scale: 1 });
                const zoom = element('zoom').value;
                const scale = zoom === 'fit' ? Math.max(.1, (element('document').clientWidth - 24) / base.width)
                    : zoom === 'height' ? Math.max(.1, (element('document').clientHeight - 24) / base.height)
                    : Math.max(.1, Math.min(4, Number(zoom) || 1));
                currentScale = scale;
                const viewport = page.getViewport({ scale });
                const ratio = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(12000000 / (viewport.width * viewport.height)));
                canvas.width = Math.floor(viewport.width * ratio);
                canvas.height = Math.floor(viewport.height * ratio);
                canvas.style.width = `${viewport.width}px`;
                canvas.style.height = `${viewport.height}px`;
                task = page.render({ canvasContext: canvas.getContext('2d'), viewport, transform: [ratio, 0, 0, ratio, 0, 0] });
                try { await task.promise; } catch (error) {
                    if (error.name !== 'RenderingCancelledException') throw error;
                }
                if (!disposed && renderedRevision === revision) {
                    canvas.hidden = false;
                    status.hidden = true;
                    input.value = pageNumber;
                    input.disabled = false;
                    element('previous').disabled = pageNumber <= 1;
                    element('next').disabled = pageNumber >= pdf.numPages;
                    zoomControls.forEach(name => { element(name).disabled = false; });
                    element('fit-width').setAttribute('aria-pressed', String(zoom === 'fit'));
                    element('fit-height').setAttribute('aria-pressed', String(zoom === 'height'));
                }
            } while (!disposed && renderedRevision !== revision);
        } catch {
            if (!disposed) {
                canvas.hidden = true;
                status.hidden = false;
                status.textContent = 'Dokument konnte nicht angezeigt werden. Bitte erneut öffnen oder herunterladen.';
            }
        } finally { task = null; running = false; }
    }

    function go(page) {
        if (!pdf || disposed) return;
        requestedPage = Math.max(1, Math.min(pdf.numPages, Math.trunc(Number(page)) || 1));
        render();
    }
    const on = (name, event, handler) => element(name).addEventListener(event, handler, { signal: listeners.signal });
    on('previous', 'click', () => go(requestedPage - 1));
    on('next', 'click', () => go(requestedPage + 1));
    on('page', 'change', () => go(input.value));
    on('zoom', 'change', () => pdf && render());
    function zoomBy(factor) {
        if (!pdf || disposed) return;
        const value = String(Math.round(Math.min(4, Math.max(.1, currentScale * factor)) * 100) / 100);
        const custom = element('custom-zoom');
        custom.value = value;
        custom.textContent = `${Math.round(Number(value) * 100)} %`;
        custom.hidden = false;
        element('zoom').value = value;
        render();
    }
    on('zoom-in', 'click', () => zoomBy(1.25));
    on('zoom-out', 'click', () => zoomBy(.8));
    for (const [control, mode] of [['fit-width', 'fit'], ['fit-height', 'height']]) {
        on(control, 'click', () => { if (pdf) { element('zoom').value = mode; render(); } });
    }
    const observer = new ResizeObserver(() => {
        clearTimeout(resize);
        resize = setTimeout(() => !disposed && pdf && ['fit', 'height'].includes(element('zoom').value) && render(), 150);
    });
    observer.observe(element('document'));

    viewers.set(root, { url: root.dataset.pdfUrl, type: root.dataset.mediaType, dispose() {
        disposed = true;
        listeners.abort();
        observer.disconnect();
        clearTimeout(resize);
        task?.cancel();
        loadingTask?.destroy().catch(() => {});
        canvas.width = canvas.height = 0;
    }});

    (async () => {
        try {
            const url = new URL(root.dataset.pdfUrl, location.href);
            if (url.origin !== location.origin) throw new Error('Invalid origin');
            if (root.dataset.mediaType === 'image') {
                const image = new Image();
                let cancelImageLoad;
                loadingTask = { destroy() { cancelImageLoad?.(); image.onload = image.onerror = null; image.removeAttribute('src'); return Promise.resolve(); } };
                await new Promise((resolve, reject) => {
                    cancelImageLoad = () => reject(new Error('Preview closed'));
                    image.onload = resolve;
                    image.onerror = reject;
                    image.referrerPolicy = 'no-referrer';
                    image.src = url.href;
                });
                if (disposed) return;
                pdf = { numPages: 1, async getPage() { return {
                    getViewport: ({ scale }) => ({ width: image.naturalWidth * scale, height: image.naturalHeight * scale }),
                    render({ canvasContext, viewport, transform }) {
                        canvasContext.save();
                        canvasContext.setTransform(...transform);
                        canvasContext.drawImage(image, 0, 0, viewport.width, viewport.height);
                        canvasContext.restore();
                        return { promise: Promise.resolve(), cancel() {} };
                    },
                }; } };
                input.max = 1;
                await render();
                return;
            }
            // A PDF endpoint is a full authorized read, not a range-fetch provider proxy.
            loadingTask = getDocument({ url: url.href, withCredentials: true, isEvalSupported: false,
                disableRange: true,
                cMapUrl: new URL('../vendor/pdfjs/cmaps/', import.meta.url).href, cMapPacked: true,
                standardFontDataUrl: new URL('../vendor/pdfjs/standard_fonts/', import.meta.url).href,
                wasmUrl: new URL('../vendor/pdfjs/wasm/', import.meta.url).href });
            pdf = await loadingTask.promise;
            if (disposed) return;
            input.max = pdf.numPages;
            element('pages').textContent = `/ ${pdf.numPages}`;
            await render();
        } catch {
            if (!disposed) status.textContent = 'Dokument ist derzeit nicht verfügbar. Bitte erneut öffnen oder herunterladen.';
        }
    })();
}

let scheduled = false;
function scan() {
    scheduled = false;
    for (const [root, viewer] of viewers) {
        if (!root.isConnected || !root.getClientRects().length || root.dataset.pdfUrl !== viewer.url || root.dataset.mediaType !== viewer.type) {
            viewer.dispose();
            viewers.delete(root);
        }
    }
    document.querySelectorAll('[data-clubunio-pdf-viewer]').forEach(root => {
        if (!viewers.has(root) && root.getClientRects().length) mount(root);
    });
}
new MutationObserver(() => {
    if (!scheduled) { scheduled = true; requestAnimationFrame(scan); }
}).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-pdf-url', 'style', 'class', 'hidden', 'aria-hidden'] });
document.addEventListener('livewire:navigated', scan);
scan();
