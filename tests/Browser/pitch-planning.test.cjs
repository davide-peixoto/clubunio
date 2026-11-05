const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Render fixtures first: CLUBUNIO_PITCH_WEB_CAPTURE=1 php vendor/bin/phpunit --filter optional_visual_fixture.
// Geometry/DOM checks only. These static fixtures omit the authenticated panel shell,
// brand asset hooks, actual font loading and Livewire/Alpine initialization. Visible
// delayed-loading spinners are a fixture limitation, not a runtime acceptance result.
// Screenshots do not establish unified-design, modal behavior or end-to-end acceptance.
test('pitch planner static geometry stays contained at desktop/mobile sizes', async () => {
    const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
    const root = path.resolve(__dirname, '../..');
    const fixtures = JSON.parse(fs.readFileSync(path.join(root, 'storage/app/pitch-web-qa.json'), 'utf8'));
    const css = ['vendor/filament/filament/dist/theme.css', 'public/css/pitch-planning.css']
        .map(file => fs.readFileSync(path.join(root, file), 'utf8')).join('\n');
    const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}) });
    try {
        const page = await browser.newPage();
        for (const width of [1440, 768, 390, 320]) {
            await page.setViewportSize({ width, height: 900 });
            for (const [name, markup] of Object.entries(fixtures)) {
                await page.setContent('<!doctype html><html><head><meta charset="utf-8"><style>' + css
                    + '</style><style>body{margin:0;padding:16px;font-family:Arial,sans-serif;background:#fff;color:#111827}main{min-width:0;width:100%}[x-cloak], [wire\\:loading]{display:none!important}</style></head><body><main>'
                    + markup + '</main></body></html>');
                // Static snapshots intentionally do not start Livewire or make network mutations.
                await page.locator('link').evaluateAll(nodes => nodes.forEach(node => node.remove()));
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
                assert.equal(overflow, false, `${name} must not overflow page at ${width}px`);
                if (name.endsWith('-day')) {
                    await page.locator('.pitch-scroll').evaluate(el => { el.scrollTop = 1500; });
                    assert.equal(await page.locator('.pitch-column').count(), 3);
                    assert.equal(await page.locator('.pitch-entry[aria-label]').count(), name.includes('-dense-') ? 5 : 1);
                    const columns = await page.locator('.pitch-column').evaluateAll(nodes => nodes.map(n => n.getBoundingClientRect().x));
                    assert.ok(columns[1] > columns[0], 'Fields remain side by side');
                    if (width >= 1440) {
                        assert.ok(await page.locator('.pitch-scroll').evaluate(el => el.scrollWidth <= el.clientWidth + 1), 'All three pitches fit on desktop');
                    }
                    if (name.includes('-dense-')) {
                        const cards = await page.locator('.pitch-entry').evaluateAll(nodes => nodes.map(n => ({width:n.getBoundingClientRect().width, title:n.getAttribute('title')})));
                        assert.ok(cards[4].width > cards[0].width * 3, 'Later singleton uses the full pitch width');
                        assert.ok(cards.every(c => c.title.includes('Dense training')), 'Condensed cards retain descriptive tooltips');
                    }
                    if (width <= 390) {
                        const scrolled = await page.locator('.pitch-scroll').evaluate(el => { el.scrollLeft = 200; return el.scrollLeft; });
                        assert.ok(scrolled > 0, 'Only the planner scrolls horizontally');
                        await page.locator('.pitch-scroll').evaluate(el => { el.scrollLeft = 0; });
                    }
                }
                if (name.endsWith('-editor')) {
                    const form = page.locator('.pitch-form');
                    assert.equal(await form.locator('select').count(), 2);
                    assert.equal(await form.locator('input[type="number"]').count(), 1);
                }
                await page.screenshot({ path: path.join(root, `storage/app/pitch-web-${name}-${width}.png`), fullPage: false });
            }
        }
    } finally { await browser.close(); }
});
