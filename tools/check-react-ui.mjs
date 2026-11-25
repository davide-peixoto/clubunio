import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { gzipSync } from 'node:zlib';

const meta = JSON.parse(await readFile('public/js/react-ui/build-meta.json', 'utf8'));
const manifest = JSON.parse(await readFile('public/js/react-ui/manifest.json', 'utf8'));
const output = meta.outputs;
const runtime = Object.entries(output).filter(([, data]) => Object.keys(data.inputs).some(name => name.endsWith('react-dom-client.production.js')));
assert.equal(runtime.length, 1, 'React DOM must exist in only one shared output');
function initial(path, seen = new Set()) {
    if (seen.has(path)) return seen;
    seen.add(path);
    for (const dependency of output[path].imports) if (dependency.kind !== 'dynamic-import' && !dependency.external) initial(dependency.path, seen);
    return seen;
}
const dashboard = initial(`public/${manifest.dashboard}`, initial(`public/${manifest.navigation}`));
const chart = Object.entries(output).find(([, data]) => data.entryPoint === 'resources/js/dashboard-charts.jsx');
assert.ok(chart, 'Charts need a separate lazy module');
assert.ok(!dashboard.has(chart[0]), 'Do not eagerly download the chart renderer');
const sizes = async paths => {
    let raw = 0, gzip = 0;
    for (const path of paths) { const bytes = await readFile(path); raw += bytes.length; gzip += gzipSync(bytes).length; }
    return { files: paths.size, raw, gzip };
};
const before = await sizes(new Set(['public/js/clubunio-react-navigation.js', 'public/js/clubunio-dashboard.js']));
const after = await sizes(dashboard);
assert.ok(after.gzip < before.gzip * .7, 'Initial dashboard should save at least 30% compressed JS');
console.log(JSON.stringify({ before, after, chartGzip: gzipSync(await readFile(chart[0])).length, oneSharedReactRuntime: true }, null, 2));
