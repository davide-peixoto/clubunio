import { build } from 'esbuild';
import { renderToStaticMarkup } from 'react-dom/server';
import React from 'react';
import assert from 'node:assert/strict';

const output = await build({ entryPoints: ['resources/js/dashboard-charts.jsx'], bundle: true,
    write: false, platform: 'node', format: 'esm', external: ['react'], jsx: 'automatic' });
// Resolve React relative to the repository without writing a generated module to source.
const { writeFile, unlink } = await import('node:fs/promises');
const path = new URL('../.dashboard-chart-check.mjs', import.meta.url);
try {
    await writeFile(path, output.outputFiles[0].text);
    const { default: Chart } = await import(path.href);
    for (const type of ['bar', 'doughnut']) {
        for (const values of [[0, 0], [17, 23], [-3, 12], [1e8, 2]]) {
            const html = renderToStaticMarkup(React.createElement(Chart, {
                heading: 'Synthetic', type, locale: 'de-CH', empty: 'Empty', total: 'Total',
                data: { labels: ['A', '<script>unsafe</script>'], datasets: [
                    { label: 'Count', data: values }, ...(type === 'bar' ? [{ label: 'Other', data: [4, 9] }] : [])] },
            }));
            assert(!html.includes('NaN') && !html.includes('Infinity'));
            assert(!html.includes('<script>unsafe</script>'));
            assert(html.includes('&lt;script&gt;unsafe&lt;/script&gt;'));
            assert(html.includes('role="img"'));
        }
    }
    const empty = renderToStaticMarkup(React.createElement(Chart, { data: { labels: [], datasets: [] }, empty: 'No data' }));
    assert(empty.includes('No data'));
    console.log('9 chart scenarios passed: bars/donuts, zeros, negatives, large values, empty data and escaped labels.');
} finally { await unlink(path).catch(() => {}); }
