import { build } from 'esbuild';
await build({ entryPoints: ['resources/js/social-studio.js'], bundle: true, minify: true,
    format: 'esm', outfile: 'public/js/clubunio-social-workspace.js', legalComments: 'eof',
    define: { 'process.env.NODE_ENV': '"production"' } });
