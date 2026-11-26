import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
import { setNavigationMotion } from '../../resources/js/navigation-motion.mjs';

const head = await readFile(new URL('../../resources/views/clubunio/react-navigation-head.blade.php', import.meta.url), 'utf8');
const bootstrap = head.match(/<script>([\s\S]+?)<\/script>/)[1];
const css = await readFile(new URL('../../public/css/clubunio-react-navigation.css', import.meta.url), 'utf8');

for (const [mode, systemReduced, expected] of [
    [undefined, false, 'full'], [undefined, true, 'reduced'],
    ['full', true, 'full'], ['reduced', false, 'reduced'], ['invalid', true, 'reduced'],
]) {
    test(`motion preference ${mode}/${systemReduced} does not rewrite transition opt-in`, () => {
        const document = { documentElement: { dataset: {} } };
        const matchMedia = () => ({ matches: systemReduced });
        globalThis.document = document;
        globalThis.matchMedia = matchMedia;
        try {
            assert.equal(setNavigationMotion(mode), expected === 'full');
            assert.equal(document.documentElement.dataset.cuMotion, expected);
            assert.equal(setNavigationMotion(mode), expected === 'full');
            vm.runInNewContext(bootstrap, { document, matchMedia,
                localStorage: { getItem: key => key === 'clubunio.ui.motion' ? mode : null } });
            assert.equal(document.documentElement.dataset.cuMotion, expected);
        } finally {
            delete globalThis.document;
            delete globalThis.matchMedia;
        }
    });
}

test('storage refusal and legacy preferences retain the motion fallback', () => {
    for (const store of [
        { getItem() { throw new Error('denied'); } },
        { getItem: key => key === 'clubunio.dashboard.motion' ? 'reduced' : null },
    ]) {
        const document = { documentElement: { dataset: {} } };
        vm.runInNewContext(bootstrap, { document, matchMedia: () => ({ matches: true }), localStorage: store });
        assert.equal(document.documentElement.dataset.cuMotion, 'reduced');
    }
});

test('stylesheet owns a stable opt-in and zero-duration reduced motion', () => {
    assert.match(css, /@view-transition\s*\{\s*navigation:\s*auto;\s*\}/);
    assert.match(css, /\[data-cu-motion=reduced\]\{--cu-nav-duration:0ms\}/);
    assert.match(css, /prefers-reduced-motion:reduce[^}]+--cu-nav-duration:0ms/);
    assert.match(css, /::view-transition-group\(\*\)\{animation-duration:var\(--cu-nav-duration\)\}/);
});
