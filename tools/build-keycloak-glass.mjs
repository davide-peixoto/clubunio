import { build } from 'esbuild';

await build({ entryPoints: ['resources/js/keycloak-glass.jsx'], bundle: true, minify: true,
    format: 'iife', target: ['es2020'],
    outfile: 'deploy/keycloak/themes/clubunio-glass-dev/login/resources/js/clubunio-glass.js',
    define: { 'process.env.NODE_ENV': '"production"' }, legalComments: 'eof' });
