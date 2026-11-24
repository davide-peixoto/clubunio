import { build } from 'esbuild';

await build({ entryPoints: ['resources/js/react-navigation.jsx'], bundle: true, minify: true,
    format: 'iife', outfile: 'public/js/clubunio-react-navigation.js',
    define: { 'process.env.NODE_ENV': '"production"' }, legalComments: 'eof' });
