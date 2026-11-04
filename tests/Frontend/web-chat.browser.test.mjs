// Run with PLAYWRIGHT_MODULE pointing to an installed playwright/index.mjs.
// Uses native Chrome and an ephemeral fixture server only. No Laravel or DB calls.
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile, mkdir} from 'node:fs/promises';
import {createServer} from 'node:http';
import {pathToFileURL} from 'node:url';
import {tmpdir} from 'node:os';
import path from 'node:path';

const root = new URL('../../', import.meta.url);
const read = file => readFile(new URL(file, root), 'utf8');
const blade = await read('resources/views/clubunio/chat/page.blade.php');
const keys = [...blade.matchAll(/foreach \(\[([^\]]+)\]/g)].flatMap(m => [...m[1].matchAll(/'([^']+)'/g)].map(x => x[1]));
const catalogs = Object.fromEntries(await Promise.all(['de', 'en'].map(async lang => [lang, JSON.parse(await read(`lang/${lang}.json`))])));
const capabilities = {view: true, send: true, upload: true, download: true, poll: true, announce: true, moderate: true, groups: true, uploads_enabled: true, ai_enabled: true};
const context = {tenant: 1, revision: '00000000-0000-4000-8000-000000000001'};
const preference = {revision: 0, translation_enabled: true, target_language: 'en', language_policy: {enabled: ['de', 'en'], default: 'de'}};
const chats = [1, 2, 3].map(id => ({id, kind: id === 3 ? 'announcements' : 'team', title: `Team ${id}`, can_write: true, can_poll: true, can_link_event: true, can_moderate: true, team_season_id: id, muted: false, unread: 2, chat_preferences: preference}));
const message = (id = 10, chat = 1) => ({id, conversation_id: chat, author_id: 7, author_name: 'Fixture member', kind: 'text', text: 'Fixture message', revision: 1, created_at: '2026-11-04T10:00:00Z', can_edit: true, can_delete: true, can_react: true, reactions: [], deleted: false});
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return {promise, resolve}; };
let browser, server, base;
const screenshots = path.join(tmpdir(), 'clubunio-web-chat-frontend');

async function fixtureHtml(locale = 'en') {
    const labels = Object.fromEntries(keys.map(k => [k, catalogs[locale][k]]));
    const config = {base: '/chat-data', context, actorId: 7, locale, languages: {de: 'Deutsch', en: 'English'}, capabilities};
    let html = blade.slice(blade.indexOf('<section'), blade.lastIndexOf('</section>') + 10);
    const escape = text => text.replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll('<', '&lt;');
    html = html.replace(/data-config="\{\{.*?\}\}"/, `data-config="${escape(JSON.stringify(config))}"`)
        .replace(/data-labels="\{\{.*?\}\}"/, `data-labels="${escape(JSON.stringify(labels))}"`)
        .replace(/\{\{ __\('([^']+)'\) \}\}/g, (_, key) => escape(catalogs[locale][key] || key));
    for (const match of [...html.matchAll(/<x-filament::icon icon="heroicon-([^"]+)" \/>/g)]) {
        html = html.replace(match[0], await read(`vendor/blade-ui-kit/blade-heroicons/resources/svg/${match[1]}.svg`));
    }
    return `<!doctype html><html lang="${locale}"><head><meta name="csrf-token" content="fixture-csrf"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/chat.css"><style>body{margin:12px;font-family:Arial}button,input,textarea{font:inherit}</style></head><body>${html}<script type="module" src="/chat.js"></script></body></html>`;
}

before(async () => {
    const {chromium} = await import(process.env.PLAYWRIGHT_MODULE ? pathToFileURL(process.env.PLAYWRIGHT_MODULE).href : 'playwright');
    browser = await chromium.launch({channel: process.env.CHAT_BROWSER || 'chrome', headless: true});
    server = createServer(async (req, res) => {
        const pathname = new URL(req.url, 'http://fixture').pathname;
        const routes = {'/chat.js': ['text/javascript', () => read('public/js/clubunio-chat.js')], '/chat.css': ['text/css', () => read('public/css/clubunio-chat.css')], '/': ['text/html', () => fixtureHtml()], '/de': ['text/html', () => fixtureHtml('de')]};
        if (!routes[pathname]) { res.writeHead(404); res.end(); return; }
        const [type, body] = routes[pathname]; res.setHeader('Content-Type', `${type}; charset=utf-8`); res.end(await body());
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    base = `http://127.0.0.1:${server.address().port}`;
    await mkdir(screenshots, {recursive: true});
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });

async function setup(t, handlers = {}, options = {}) {
    const page = await browser.newPage({viewport: {width: 1280, height: 900}, ...options});
    page.setDefaultTimeout(8000);
    t.after(() => page.close());
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const defaults = {
        list: () => ({data: {conversations: chats, capabilities}}), conversation: input => ({data: chats.find(c => c.id === Number(input.chat_id))}),
        messages: input => ({data: [message(10, Number(input.chat_id))], meta: {change_cursor: 10, has_more: false}}),
        changes: () => ({data: [], meta: {cursor: 10, can_write: true, can_moderate: true, has_more: false}}),
        read: () => ({data: {read: true}}), preferences: () => ({data: preference}), blocks: () => ({data: []}),
        preview: () => ({data: {count: 1, recipients: [{id: 9, name: 'Fixture recipient'}], fingerprint: 'a'.repeat(64)}}),
        events: () => ({data: [{id: 8, title: 'Fixture event', starts_at: '2026-12-04T10:00:00Z'}]}),
        send: input => ({data: {...message(20, input.chat_id), ...input}}), 'attachment-remove': () => ({data: {removed: true}}),
    };
    await page.route('**/chat-data/*', async route => {
        const request = route.request(), url = new URL(request.url()), op = url.pathname.split('/').pop();
        const multipart = request.headers()['content-type']?.includes('multipart/form-data');
        const input = request.method() === 'GET' ? Object.fromEntries(url.searchParams) : multipart ? {raw: request.postData()} : request.postDataJSON();
        calls.push({op, input, method: request.method(), headers: request.headers()});
        try {
            const handler = handlers[op] || defaults[op]; assert.ok(handler, `Unexpected request ${op}`);
            const result = await handler(input, calls);
            if (result?.abort) { await route.abort(); return; }
            if (result?.bytes) { await route.fulfill({status: 200, contentType: result.mime, body: result.bytes}); return; }
            await route.fulfill({status: result?.status || 200, contentType: 'application/json', body: JSON.stringify(result?.body || result)});
        } catch (error) { errors.push(error.message); await route.fulfill({status: 500, body: '{}'}); }
    });
    await page.goto(base + (options.locale === 'de' ? '/de' : '/'));
    await page.waitForFunction(() => document.querySelector('[data-cu-chat]')?.clubunioChat?.chats.length > 0);
    t.after(() => assert.deepEqual(errors, [], 'No runtime or unexpected API errors'));
    const select = async (id = 1) => { await page.evaluate(id => document.querySelector('[data-cu-chat]').clubunioChat.select(id), id); };
    return {page, calls, select};
}

test('every dynamic Blade label is in both catalogs, with matching placeholders', () => {
    assert.ok(keys.length > 80);
    for (const key of keys) for (const locale of ['de', 'en']) {
        assert.ok(Object.hasOwn(catalogs[locale], key), `${locale}: ${key}`);
        assert.deepEqual(catalogs[locale][key].match(/:[a-z_]+/g) || [], key.match(/:[a-z_]+/g) || []);
    }
});

test('session context, CSRF, text safety, edit and immutable retry payload', async t => {
    let attempt = 0;
    const {page, calls, select} = await setup(t, {send: input => ++attempt === 1 ? {abort: true} : {data: {...message(20), ...input}}, edit: input => ({data: {...message(), revision: 2, text: input.text}})});
    await select();
    await page.locator('[data-messages] button[title="Edit"]').click();
    await page.locator('dialog textarea').fill('Edited fixture'); await page.locator('dialog button[type=submit]').click();
    await page.waitForFunction(() => document.querySelector('[data-messages]').textContent.includes('Edited fixture'));
    await page.locator('[data-text]').fill('<img src=x onerror=alert(1)>'); await page.locator('[data-send]').click();
    await page.waitForFunction(() => document.querySelector('[data-cu-chat]').clubunioChat.draft().uncertain);
    assert.equal(await page.locator('[data-text]').isDisabled(), true);
    await page.locator('[data-send]').click();
    await page.waitForFunction(() => document.querySelector('[data-text]').value === '');
    const sends = calls.filter(c => c.op === 'send'); assert.equal(sends.length, 2); assert.deepEqual(sends[0].input, sends[1].input);
    assert.deepEqual(sends[0].input.context, context); assert.equal(sends[0].headers['x-csrf-token'], 'fixture-csrf');
    assert.equal(calls.find(c => c.op === 'conversation').input['context[tenant]'], '1');
    assert.equal(await page.locator('[data-messages] img').count(), 0);
});

test('stale conversation and preview responses cannot cross conversations', async t => {
    const delayed = deferred(), preview = deferred();
    const {page, select, calls} = await setup(t, {conversation: input => Number(input.chat_id) === 1 ? delayed.promise : {data: chats.find(c => c.id === Number(input.chat_id))}, preview: () => preview.promise});
    await page.evaluate(() => { window.first = document.querySelector('[data-cu-chat]').clubunioChat.select(1); });
    await select(2); delayed.resolve({data: chats[0]}); await page.evaluate(() => window.first);
    assert.equal(await page.locator('[data-title]').textContent(), 'Team 2');
    await select(3); await page.locator('[data-text]').fill('Announcement');
    await page.evaluate(() => { window.preview = document.querySelector('[data-cu-chat]').clubunioChat.send(); });
    await select(2); preview.resolve({data: {count: 1, recipients: [], fingerprint: 'b'.repeat(64)}}); await page.evaluate(() => window.preview);
    assert.equal(await page.locator('dialog').evaluate(d => d.open), false); assert.equal(calls.filter(c => c.op === 'send').length, 0);
});

test('announcement recipient conflict requires a new preview and confirmation', async t => {
    const {page, calls, select} = await setup(t, {send: () => ({status: 409})}); await select(3);
    await page.locator('[data-text]').fill('Announcement'); await page.locator('[data-send]').click();
    await page.locator('dialog button[type=submit]').click();
    await page.waitForFunction(() => !document.querySelector('[data-error]').hidden);
    assert.equal(await page.locator('[data-text]').isDisabled(), false);
    await page.locator('[data-send]').click(); await page.locator('dialog button[type=submit]').waitFor();
    assert.equal(calls.filter(c => c.op === 'preview').length, 2); assert.equal(calls.filter(c => c.op === 'send').length, 1);
    assert.equal(calls.find(c => c.op === 'send').input.recipient_fingerprint, 'a'.repeat(64));
});

test('upload timeout replays UUID, scan retry then cancellation preserves text', async t => {
    let uploads = 0, scans = 0;
    const {page, calls, select} = await setup(t, {upload: () => ++uploads === 1 ? {abort: true} : {data: {id: 'attachment-fixture', state: 'quarantine'}}, scan: () => ++scans === 1 ? {status: 503} : {data: {state: 'clean'}}});
    await select(); await page.locator('[data-text]').fill('Keep caption');
    await page.locator('[data-file]').setInputFiles({name: 'fixture.png', mimeType: 'image/png', buffer: Buffer.from('fixture')});
    const retry = page.locator('[data-selection] button[title="Retry"]'); await retry.waitFor(); await retry.click();
    await page.waitForFunction(() => document.querySelector('[data-cu-chat]').clubunioChat.draft().attachment?.state === 'quarantine');
    assert.equal(await page.locator('[data-send]').isDisabled(), true);
    await retry.click(); await page.waitForFunction(() => document.querySelector('[data-cu-chat]').clubunioChat.draft().attachment?.state === 'clean');
    const stages = calls.filter(c => c.op === 'upload').map(c => /name="client_id"\r\n\r\n([^\r]+)/.exec(c.input.raw)?.[1]);
    assert.equal(stages.length, 2); assert.ok(stages[0]); assert.equal(stages[0], stages[1]);
    await page.locator('[data-selection] button[title="Clear selection"]').click();
    await page.waitForFunction(() => !document.querySelector('[data-cu-chat]').clubunioChat.draft().attachment);
    assert.equal(await page.locator('[data-text]').inputValue(), 'Keep caption'); assert.equal(calls.filter(c => c.op === 'attachment-remove').length, 1);
});

test('private image preview fetches bytes into revocable blob URL', async t => {
    const attachment = {id: 'private-file', name: 'fixture.png', mime: 'image/png'};
    const {page, calls, select} = await setup(t, {messages: () => ({data: [{...message(), attachment}], meta: {change_cursor: 10, has_more: false}}), download: () => ({mime: 'image/png', bytes: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZ1sAAAAASUVORK5CYII=', 'base64')})});
    await select(); await page.locator('[data-messages] button[title="Preview attachment"]').click();
    await page.locator('dialog img').waitFor(); assert.match(await page.locator('dialog img').getAttribute('src'), /^blob:/);
    assert.equal(calls.filter(c => c.op === 'download').length, 1);
    await page.locator('[data-action=close-dialog]').click();
    await page.waitForFunction(() => document.querySelector('[data-cu-chat]').clubunioChat.objectUrls.size === 0);
});

test('event selection is only a draft; settings uses configured policy intersection', async t => {
    const {page, calls, select} = await setup(t); await select();
    await page.locator('[data-action=event]').click(); await page.getByRole('button', {name: /Fixture event/}).click();
    assert.match(await page.locator('[data-selection]').textContent(), /Fixture event/); assert.equal(calls.filter(c => c.op === 'send').length, 0);
    await page.evaluate(() => { const app = document.querySelector('[data-cu-chat]').clubunioChat; app.config.languages = {en: 'English'}; });
    await page.locator('[data-action=settings]').click(); await page.locator('dialog select').waitFor();
    assert.deepEqual(await page.locator('dialog option').evaluateAll(options => options.map(o => o.value)), ['en']);
});

test('reaction people pagination, voting, moderation and AI use real DOM controls', async t => {
    const poll = {id: 4, multiple: true, closes_at: '2099-01-01T00:00:00Z', options: [{id: 'choice-a', text: 'A', count: 1}, {id: 'choice-b', text: 'B', count: 0}], my_choices: []};
    const row = {...message(), poll, reactions: [{emoji: '👍', count: 1, mine: false}]};
    const {page, calls, select} = await setup(t, {
        messages: () => ({data: [row], meta: {change_cursor: 10, has_more: false}}), vote: input => ({data: {...poll, my_choices: input.choices}}),
        reactions: input => ({data: [{emoji: '👍', name: input.after === '0' ? 'First person' : 'Next person'}], meta: {has_more: input.after === '0', next_after: 8}}),
        reports: () => ({data: [{id: 5, message: row, reason: 'Fixture reason'}]}), resolve: () => ({data: {resolved: true}}),
        ai: input => ({data: {text: `AI ${input.operation}`}}),
    });
    await select(); await page.locator('.cu-chat-poll input').first().check(); await page.locator('.cu-chat-poll button[type=submit]').click();
    await page.waitForFunction(() => !document.querySelector('[data-cu-chat]').clubunioChat.busy);
    assert.deepEqual(calls.find(c => c.op === 'vote').input.choices, ['choice-a']);
    await page.locator('.cu-chat-reaction').click(); await page.getByRole('button', {name: 'Show more', exact: true}).click();
    await page.getByText('👍 Next person', {exact: true}).waitFor(); await page.locator('[data-action=close-dialog]').click();
    await page.locator('[data-action=reports]').click(); await page.getByRole('button', {name: 'Resolve', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('dialog li') === null); await page.locator('[data-action=close-dialog]').click();
    await page.locator('[data-text]').fill('Draft for AI'); await page.locator('[data-action=ai]').click();
    await page.locator('dialog input[type=checkbox]').check(); await page.locator('dialog button[type=submit]').click();
    await page.getByText('AI correct', {exact: true}).waitFor(); await page.locator('dialog select').selectOption('shorten'); await page.locator('dialog button[type=submit]').click();
    await page.getByText('AI shorten', {exact: true}).waitFor();
    assert.match(await page.locator('dialog').innerText(), /Use text/, 'AI result includes the apply command');
    await page.getByRole('button', {name: 'Use text', exact: true}).click();
    assert.equal(await page.locator('[data-text]').inputValue(), 'AI shorten');
    const requests = calls.filter(c => c.op === 'ai'); assert.notEqual(requests[0].input.request_key, requests[1].input.request_key);
});

test('moderation resolution works without send permission', async t => {
    const {page, calls, select} = await setup(t, {
        list: () => ({data: {conversations: chats, capabilities: {...capabilities, send: false}}}),
        reports: () => ({data: [{id: 5, message: message(), reason: 'Fixture reason'}]}), resolve: () => ({data: {resolved: true}}),
    });
    await select(); assert.equal(await page.locator('[data-composer]').isVisible(), false);
    assert.equal(calls.filter(c => c.op === 'read').length, 1, 'View-only users may mark visible messages read');
    await page.locator('[data-action=reports]').click(); await page.getByRole('button', {name: 'Resolve', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('dialog li') === null);
    assert.equal(calls.filter(c => c.op === 'resolve').length, 1); assert.equal(calls.filter(c => c.op === 'send').length, 0);
});

test('membership boundary change clears old history and loads fresh scope', async t => {
    let pages = 0;
    const {page, select} = await setup(t, {
        messages: () => ({data: [message(++pages === 1 ? 10 : 30)], meta: {joined_after: pages === 1 ? 0 : 20, change_cursor: 30, has_more: false}}),
        changes: () => ({data: [], meta: {joined_after: 20, cursor: 30, can_write: true, can_moderate: true, has_more: false}}),
    });
    await select(); await page.evaluate(() => document.querySelector('[data-cu-chat]').clubunioChat.poll());
    assert.equal(await page.locator('[data-message-id="10"]').count(), 0); assert.equal(await page.locator('[data-message-id="30"]').count(), 1);
});

test('uncertain send followed by conflict stays frozen; refreshed list preview is text only', async t => {
    let attempts = 0;
    const {page, select, calls} = await setup(t, {
        send: () => ++attempts === 1 ? {abort: true} : {status: 409},
        list: () => ({data: {conversations: chats.map(c => ({...c, last_message: {text: '<b>Recent text</b>'}})), capabilities}}),
    });
    assert.equal(await page.locator('.cu-chat-last-message b').count(), 0); await select();
    await page.locator('[data-text]').fill('Do not duplicate'); await page.locator('[data-send]').click();
    await page.waitForFunction(() => document.querySelector('[data-cu-chat]').clubunioChat.draft().uncertain);
    await page.locator('[data-send]').click(); await page.waitForFunction(() => !document.querySelector('[data-cu-chat]').clubunioChat.busy);
    assert.equal(await page.locator('[data-text]').isDisabled(), true);
    assert.deepEqual(calls.filter(c => c.op === 'send')[0].input, calls.filter(c => c.op === 'send')[1].input);
});

for (const width of [320, 390, 1280]) test(`native Chrome layout ${width}px, German, same chat DOM`, async t => {
    const {page, select} = await setup(t, {}, {viewport: {width, height: 900}, locale: 'de'}); await select();
    await page.locator('[data-text]').fill('Ein langer Entwurf bleibt im selben Chat.');
    assert.equal(await page.locator('[data-composer]').isVisible(), true);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    const controls = await page.locator('[data-composer] button').evaluateAll(nodes => nodes.filter(n => n.getBoundingClientRect().width).map(n => { const r = n.getBoundingClientRect(); return {x: r.x, y: r.y, right: r.right, bottom: r.bottom}; }));
    for (let i = 0; i < controls.length; i++) for (let j = i + 1; j < controls.length; j++) {
        const a = controls[i], b = controls[j]; assert.ok(a.right <= b.x || b.right <= a.x || a.bottom <= b.y || b.bottom <= a.y, 'No overlapping composer buttons');
    }
    await page.screenshot({path: path.join(screenshots, `chat-${width}.png`), fullPage: true});
    if (width < 760) { await page.locator('[data-action=back]').click(); assert.equal(await page.locator('.cu-chat-list').isVisible(), true); await select(); assert.equal(await page.locator('[data-text]').inputValue(), 'Ein langer Entwurf bleibt im selben Chat.'); }
});
