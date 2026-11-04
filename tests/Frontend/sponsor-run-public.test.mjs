import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const source = await readFile(new URL('../../public/js/sponsor-run-public.js', import.meta.url), 'utf8');
const catalog = JSON.parse(await readFile(new URL('../../lang/parts/views.en.json', import.meta.url), 'utf8'));
const token = 'a'.repeat(64);
const settle = () => new Promise(resolve => setImmediate(resolve));

function mount(locale, fragment, reply) {
    const translate = key => locale === 'en' ? catalog[key] : key;
    const text = {
        invalidLink: translate('Link ist ungültig oder abgelaufen.'),
        confirmPledge: translate('Bitte Sponsorenzusage verbindlich bestätigen.'),
        eventDeadline: translate(':date / Zusagefrist :deadline'),
        requestFailed: translate('Anfrage fehlgeschlagen. Bitte erneut versuchen.'),
    };
    const nodes = new Map();
    for (const id of ['sr-message', 'sr-pledge', 'sr-confirm', 'sr-title', 'sr-alias', 'sr-date', 'sr-event']) {
        const node = new EventTarget();
        Object.assign(node, { hidden: true, textContent: '', dataset: text, button: { disabled: false },
            elements: { consent: { checked: true } }, querySelector() { return this.button; } });
        nodes.set(id, node);
    }
    const requests = [], history = [];
    runInNewContext(source, {
        window: { location: { hash: '#'+fragment, pathname: '/sponsor-run' } },
        history: { replaceState: (...args) => history.push(args) },
        document: { getElementById: id => nodes.get(id), querySelector: () => ({ content: 'csrf-token' }) },
        crypto: { randomUUID: () => 'stable-request-key' },
        FormData: class { *[Symbol.iterator]() { yield ['sponsor_name', 'Speichern <b>user</b>']; } },
        fetch: async (url, options) => {
            requests.push({ url, options, body: JSON.parse(options.body) });
            return reply(url, requests.length);
        },
    });
    return { nodes, requests, history, text, submit(id) {
        nodes.get(id).dispatchEvent(new Event('submit', { cancelable: true }));
        return settle();
    } };
}

const json = (body, ok = true) => ({ ok, json: async () => body });
for (const locale of ['de', 'en']) {
    test(`${locale}: invalid links and confirmation prompts use supplied translations`, async () => {
        const invalid = mount(locale, 'invalid', () => assert.fail('No request for invalid token'));
        assert.equal(invalid.nodes.get('sr-message').textContent, invalid.text.invalidLink);
        assert.equal(invalid.requests.length, 0);
        assert.equal(invalid.history[0][2], '/sponsor-run');
        const confirmation = mount(locale, 'confirm='+token, () => json({ message: 'Server confirmation <b>unchanged</b>' }));
        assert.equal(confirmation.nodes.get('sr-message').textContent, confirmation.text.confirmPledge);
        assert.equal(confirmation.nodes.get('sr-confirm').hidden, false);
        await confirmation.submit('sr-confirm');
        assert.equal(confirmation.nodes.get('sr-message').textContent, 'Server confirmation <b>unchanged</b>');
        assert.equal(confirmation.requests[0].body.token, token);
        assert.equal(confirmation.nodes.get('sr-confirm').hidden, true);
    });

    test(`${locale}: info and submission preserve user data and stable request identifiers`, async () => {
        const page = mount(locale, token, (url, count) => count === 1
            ? json({ title: 'Speichern', alias: '<script>user</script>', event_date: ':deadline', registration_deadline: '2026-12-01' })
            : json({ message: 'Saved by server' }));
        await settle();
        assert.equal(page.nodes.get('sr-title').textContent, 'Speichern');
        assert.equal(page.nodes.get('sr-alias').textContent, '<script>user</script>');
        assert.equal(page.nodes.get('sr-date').textContent,
            page.text.eventDeadline.replace(/:date|:deadline/g, key => key === ':date' ? ':deadline' : '2026-12-01'));
        await page.submit('sr-pledge');
        const sent = page.requests[1];
        assert.equal(sent.body.sponsor_name, 'Speichern <b>user</b>');
        assert.equal(sent.body.request_key, 'stable-request-key');
        assert.equal(sent.body.payment_mode, 'invoice');
        assert.equal(sent.body.consent, true);
        assert.equal(sent.options.credentials, 'same-origin');
        assert.equal(sent.options.headers['X-CSRF-TOKEN'], 'csrf-token');
        assert.equal(page.nodes.get('sr-message').textContent, 'Saved by server');
    });

    test(`${locale}: network, invalid JSON and empty errors have translated retry messages`, async () => {
        for (const reply of [
            () => { throw new TypeError('Failed to fetch'); },
            () => ({ ok: false, json: async () => { throw new SyntaxError('Unexpected token'); } }),
            () => json({}, false),
        ]) {
            const page = mount(locale, 'confirm='+token, reply);
            await page.submit('sr-confirm');
            assert.equal(page.nodes.get('sr-message').textContent, page.text.requestFailed);
            assert.equal(page.nodes.get('sr-confirm').button.disabled, false);
            assert.equal(page.nodes.get('sr-confirm').hidden, false);
        }
        const validation = mount(locale, 'confirm='+token, () => json({ errors: { name: ['Server validation <b>unchanged</b>'] } }, false));
        await validation.submit('sr-confirm');
        assert.equal(validation.nodes.get('sr-message').textContent, 'Server validation <b>unchanged</b>');
    });
}
