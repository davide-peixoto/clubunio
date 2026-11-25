import { build } from 'esbuild';
import { mkdir, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { relative } from 'node:path';

const entries = { navigation: 'resources/js/react-navigation.jsx', dashboard: 'resources/js/dashboard-glass.jsx', facility: 'resources/js/facility-planner.jsx' };
// Permit a reviewed navigation revision while another task edits those sources.
const navigationRevision = process.env.CLUBUNIO_UI_NAV_REVISION;
if (navigationRevision && !/^[a-f0-9]{7,40}$/.test(navigationRevision)) throw new Error('Use an explicit reviewed commit');
const result = await build({ entryPoints: entries, bundle: true, splitting: true, minify: true,
    format: 'esm', target: 'es2020', outdir: 'public/js/react-ui', entryNames: '[name]-[hash]', chunkNames: 'chunks/[name]-[hash]',
    define: { 'process.env.NODE_ENV': '"production"' }, legalComments: 'eof', metafile: true,
    plugins: navigationRevision ? [{ name: 'reviewed-navigation', setup(api) {
        api.onLoad({ filter: /[\\/]resources[\\/]js[\\/](react-navigation\.jsx|navigation-(motion|search)\.mjs)$/ }, args => ({
            contents: execFileSync('git', ['show', `${navigationRevision}:${relative(process.cwd(), args.path).replaceAll('\\', '/')}`], { encoding: 'utf8' }), loader: args.path.endsWith('.jsx') ? 'jsx' : 'js',
        }));
    } }] : [],
});
const manifest = {};
for (const [name, source] of Object.entries(entries)) {
    const output = Object.entries(result.metafile.outputs).find(([, data]) => data.entryPoint === source);
    if (!output) throw new Error(`Missing React entry: ${name}`);
    manifest[name] = output[0].replace(/^public\//, '');
}
manifest.preloads = {};
for (const name of Object.keys(entries)) {
    const seen = new Set();
    const visit = path => {
        for (const item of result.metafile.outputs[path].imports) {
            if (item.kind === 'dynamic-import' || item.external || seen.has(item.path)) continue;
            seen.add(item.path); visit(item.path);
        }
    };
    visit(`public/${manifest[name]}`);
    manifest.preloads[name] = [...seen].map(path => path.replace(/^public\//, ''));
}
await mkdir('public/js/react-ui', { recursive: true });
await writeFile('public/js/react-ui/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
await writeFile('public/js/react-ui/build-meta.json', JSON.stringify(result.metafile, null, 2) + '\n');
console.log('Shared React entries built:', manifest);
