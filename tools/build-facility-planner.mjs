import { build } from 'esbuild';

await build({ entryPoints: ['resources/js/facility-planner.jsx'], bundle: true, minify: true,
    format: 'iife', outfile: 'public/js/clubunio-facility-planner.js',
    define: { 'process.env.NODE_ENV': '"production"' }, legalComments: 'eof' });
