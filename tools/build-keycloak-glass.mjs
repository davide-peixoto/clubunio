import { build } from 'esbuild';
import assert from 'node:assert/strict';
import { messages } from '../resources/js/keycloak-glass-messages.js';

assert.deepEqual(Object.keys(messages.de).sort(), Object.keys(messages.en).sort(), 'Keycloak DE/EN keys differ');
for (const catalog of Object.values(messages)) {
    for (const value of Object.values(catalog)) assert.equal(typeof value === 'string' && value.length > 0, true);
}

await build({ entryPoints: ['resources/js/keycloak-glass.jsx'], bundle: true, minify: true,
    format: 'iife', target: ['es2020'],
    outfile: 'deploy/keycloak/themes/clubunio-glass-dev/login/resources/js/clubunio-glass-v6.js',
    define: { 'process.env.NODE_ENV': '"production"' }, legalComments: 'eof' });
