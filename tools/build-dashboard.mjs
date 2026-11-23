import { build } from 'esbuild';

await build({
    entryPoints: ['resources/js/dashboard-glass.jsx'],
    bundle: true,
    minify: true,
    format: 'iife',
    outfile: 'public/js/clubunio-dashboard.js',
    define: { 'process.env.NODE_ENV': '"production"' },
    legalComments: 'eof',
});
