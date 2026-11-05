// Isolated Chromium only: rendered Blade, shipped CSS, synthetic shell, no live session.
// PLAYWRIGHT_MODULE can point to an existing playwright/index.mjs installation.
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFile, mkdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const root = fileURLToPath(new URL('../../', import.meta.url));
const screenshots = path.join(tmpdir(), 'clubunio-mobile-language-header');
const css = (await Promise.all([
    'public/css/filament/filament/app.css', 'public/css/svs.css',
    'public/css/clubunio-navigation.css', 'public/css/clubunio-administration.css',
].map(file => readFile(path.join(root, file), 'utf8')))).join('\n');
const fixtures = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['-r', `
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    config(['session.driver' => 'array', 'cache.default' => 'array']);
    $result = [];
    foreach (config('clubunio-languages.enabled') as $locale => $name) {
        app()->setLocale($locale);
        $result[$locale] = ['html' => view('clubunio.language-switcher')->render(), 'label' => __('Sprache')];
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
`], {cwd: root, encoding: 'utf8', env: {...process.env, APP_ENV: 'testing', SESSION_DRIVER: 'array', CACHE_STORE: 'array'}}));
let browser;

before(async () => {
    const {chromium} = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
    browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome', headless: true});
    await mkdir(screenshots, {recursive: true});
});
after(async () => { await browser?.close(); });

for (const [locale, fixture] of Object.entries(fixtures)) {
    for (const dark of [false, true]) {
        for (const width of [320, 390, 768, 1440]) {
            test(`language header/login ${locale} ${dark ? 'dark' : 'light'} ${width}px`, async t => {
                const page = await browser.newPage({viewport: {width, height: 844}});
                t.after(() => page.close());
                // Even an accidental submission or asset reference cannot reach the app.
                await page.route('**/*', route => route.abort());
                for (const location of ['header', 'login']) {
                    const shell = location === 'header'
                        ? `<div class="fi-topbar-ctn"><nav class="fi-topbar">
                            <button class="fi-icon-btn fi-topbar-open-sidebar-btn" aria-label="Menu" style="width:36px;height:36px">M</button>
                            <div class="fi-topbar-start">ClubUnio</div>
                            <div class="fi-topbar-end">
                                <div class="fi-global-search-ctn" style="width:153.75px">
                                    <div class="fi-global-search"><div class="fi-global-search-field">
                                        <div class="fi-input-wrp"><div class="fi-input-wrp-content-ctn">
                                            <input type="search" size="15" aria-label="Search" class="fi-input fi-input-has-inline-prefix" placeholder="Search">
                                        </div></div>
                                    </div></div>
                                </div>
                                <div class="fi-dropdown fi-user-menu"><div class="fi-dropdown-trigger"><button class="fi-user-menu-trigger" aria-label="Profile"><span class="fi-avatar" style="display:block;width:32px;height:32px;background:#9ca3af;border-radius:50%"></span></button></div></div>
                            </div>${fixture.html}</nav></div>`
                        : `<main class="fi-simple-main" style="margin:24px auto;padding:24px;width:min(100%,448px)">${fixture.html}</main>`;
                    await page.setContent(`<!doctype html><html lang="${locale}" class="${dark ? 'dark' : ''}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style></head><body class="fi-body">${shell}</body></html>`);
                    const select = page.getByRole('combobox', {name: fixture.label, exact: true});
                    assert.equal(await select.count(), 1);
                    assert.equal(await select.inputValue(), locale);
                    const geometry = await select.evaluate(el => {
                        const box = node => { const r = node.getBoundingClientRect(); return {x: r.x, y: r.y, right: r.right, bottom: r.bottom, width: r.width, height: r.height}; };
                        const label = document.querySelector('label[for="cu-locale"]');
                        const avatar = document.querySelector('.fi-user-menu-trigger');
                        return {select: box(el), label: box(label), labelPosition: getComputedStyle(label).position,
                            avatar: avatar ? box(avatar) : null,
                            menu: avatar ? box(document.querySelector('.fi-user-menu')) : null,
                            search: avatar ? box(document.querySelector('.fi-global-search-ctn')) : null,
                            searchInput: avatar ? box(document.querySelector('input[type="search"]')) : null,
                            end: avatar ? box(document.querySelector('.fi-topbar-end')) : null,
                            scrollWidth: document.documentElement.scrollWidth,
                            fontSize: getComputedStyle(el).fontSize, background: getComputedStyle(el).backgroundColor,
                            hit: document.elementFromPoint(box(el).x + box(el).width / 2, box(el).y + box(el).height / 2) === el};
                    });
                    assert.equal(geometry.labelPosition, 'absolute', 'Accessible label must not take header space');
                    assert.equal(geometry.label.width, 1);
                    assert.equal(geometry.label.height, 1);
                    assert.ok(geometry.select.height >= 40);
                    assert.ok(geometry.select.x >= 0 && geometry.select.right <= width);
                    assert.ok(geometry.scrollWidth <= width, 'No page overflow');
                    assert.equal(geometry.fontSize, '16px');
                    assert.equal(geometry.background, dark ? 'rgb(24, 24, 27)' : 'rgb(255, 255, 255)');
                    assert.equal(geometry.hit, true, 'Select must not be covered by another control');
                    if (geometry.avatar) {
                        assert.ok(geometry.menu.width >= 32, 'Profile menu cannot shrink below its button');
                        assert.ok(geometry.avatar.right <= geometry.end.right + 0.01, 'Avatar stays within its flex container');
                        assert.ok(geometry.search.right <= geometry.menu.x, 'Search cannot overlap profile menu');
                        assert.ok(geometry.searchInput.right <= geometry.search.right + 0.01, 'Search input shrinks with its container');
                        assert.ok(geometry.select.x - geometry.avatar.right >= 12, 'Avatar has a real gap before the select');
                        assert.ok(geometry.avatar.y < geometry.select.bottom && geometry.avatar.bottom > geometry.select.y, 'Controls remain in one row');
                    }
                    await page.screenshot({path: path.join(screenshots, `${location}-${locale}-${dark ? 'dark' : 'light'}-${width}.png`)});
                    await page.evaluate(() => {
                        window.submissions = [];
                        document.querySelector('.cu-language-switcher').addEventListener('submit', event => {
                            event.preventDefault();
                            window.submissions.push(Object.fromEntries(new FormData(event.target)));
                        });
                    });
                    const other = Object.keys(fixtures).find(code => code !== locale);
                    await select.selectOption(other);
                    const submissions = await page.evaluate(() => window.submissions);
                    assert.equal(submissions.length, 1, 'Selection submits immediately exactly once');
                    assert.equal(submissions[0].locale, other);
                    assert.ok(Object.hasOwn(submissions[0], '_token'));
                    if (location === 'header') {
                        await page.getByRole('button', {name: 'Profile', exact: true}).focus();
                        await page.keyboard.press('Tab');
                    } else {
                        await page.evaluate(() => document.activeElement.blur());
                        await page.keyboard.press('Tab');
                    }
                    assert.equal(await select.evaluate(el => el === document.activeElement), true, 'Keyboard can reach the select');
                    assert.equal(await select.evaluate(el => getComputedStyle(el).outlineStyle), 'solid');
                    await page.keyboard.press(locale === 'de' ? 'ArrowUp' : 'ArrowDown');
                    assert.equal(await select.inputValue(), locale, 'Native keyboard choice remains available');
                    assert.equal(await page.evaluate(() => window.submissions.length), 2);
                }
            });
        }
    }
}
