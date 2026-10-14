import { getDocument, GlobalWorkerOptions } from '../vendor/pdfjs/pdf.min.js';

GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs/pdf.worker.min.js', import.meta.url).href;
const byId = id => document.getElementById(id);
const canvas = byId('pdf');
const status = byId('status');
const input = byId('page');
let pdf, requestedPage = 1, revision = 0, running = false, task;

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
            const base = page.getViewport({ scale: 1 });
            const zoom = byId('zoom').value;
            const scale = zoom === 'fit' ? Math.max(.1, (byId('document').clientWidth - 24) / base.width) : Number(zoom);
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
            if (renderedRevision === revision) {
                canvas.hidden = false;
                status.hidden = true;
                input.value = pageNumber;
                input.disabled = false;
                byId('previous').disabled = pageNumber <= 1;
                byId('next').disabled = pageNumber >= pdf.numPages;
            }
        } while (renderedRevision !== revision);
    } catch {
        canvas.hidden = true;
        status.hidden = false;
        status.textContent = 'PDF konnte nicht angezeigt werden. Bitte erneut öffnen oder herunterladen.';
    } finally { task = null; running = false; }
}

function go(page) {
    if (!pdf) return;
    requestedPage = Math.max(1, Math.min(pdf.numPages, Math.trunc(Number(page)) || 1));
    render();
}
byId('previous').addEventListener('click', () => go(requestedPage - 1));
byId('next').addEventListener('click', () => go(requestedPage + 1));
input.addEventListener('change', () => go(input.value));
byId('zoom').addEventListener('change', () => pdf && render());
let resize;
new ResizeObserver(() => {
    clearTimeout(resize);
    resize = setTimeout(() => pdf && byId('zoom').value === 'fit' && render(), 150);
}).observe(byId('document'));

try {
    const url = new URL(document.body.dataset.pdfUrl, location.href);
    if (url.origin !== location.origin) throw new Error('Invalid origin');
    pdf = await getDocument({ url: url.href, withCredentials: true, isEvalSupported: false,
        cMapUrl: new URL('../vendor/pdfjs/cmaps/', import.meta.url).href, cMapPacked: true,
        standardFontDataUrl: new URL('../vendor/pdfjs/standard_fonts/', import.meta.url).href,
        wasmUrl: new URL('../vendor/pdfjs/wasm/', import.meta.url).href }).promise;
    input.max = pdf.numPages;
    byId('pages').textContent = `/ ${pdf.numPages}`;
    await render();
} catch {
    status.textContent = 'PDF ist derzeit nicht verfügbar. Bitte erneut öffnen oder herunterladen.';
}
