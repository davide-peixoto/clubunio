import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {test} from 'node:test';
import {runInNewContext} from 'node:vm';

const script = await readFile(new URL('../../public/js/clubunio-support.js', import.meta.url), 'utf8');

function setup(responder) {
    let submit, keydown, calls = 0;
    const element = tag => ({tag, children: [], textContent: '', append(...nodes) { this.children.push(...nodes); }});
    const output = element('output');
    const button = {};
    const input = {value: 'Expense <script> marker', focus() {}, addEventListener(_, listener) { keydown = listener; }};
    const form = {
        dataset: {supportChat: '', you: 'You', assistant: 'Assistant', pending: 'Working', error: 'Failed',
            source: 'Source', knowledge: 'https://dev.example/support/club/1?tab=knowledge'},
        elements: {body: input},
        querySelector: tag => tag === 'output' ? output : button,
        addEventListener(_, handler) { submit = handler; },
        requestSubmit() { return submit({preventDefault() {}, currentTarget: form}); },
    };
    runInNewContext(script, {
        document: {querySelector: () => form, createElement: element, getElementById: () => null},
        location: {hash: '', pathname: '/support/club/1', origin: 'https://dev.example', href: 'https://dev.example/support/club/1?tab=assistant'},
        FormData: class { set() {} }, URL, AbortController, setTimeout, clearTimeout,
        fetch: async () => { calls++; return responder(); },
    });
    return {form, input, button, output, send: () => form.requestSubmit(), calls: () => calls,
        key: event => keydown(event)};
}

test('renders user and assistant turns with safe text and a scoped source link', async () => {
    const chat = setup(async () => ({ok: true, json: async () => ({data: {text: 'Open **Expenses** <img>', ai: true, source: 'expense'}})}));
    await chat.send();
    assert.equal(chat.output.children.length, 2);
    assert.equal(chat.output.children[0].children[1].textContent, 'Expense <script> marker');
    const response = chat.output.children[1];
    assert.equal(response.className, 'cs-chat-message cs-chat-assistant');
    assert.equal(response.children.at(-1).href, 'https://dev.example/support/club/1?tab=knowledge#faq-expense');
    assert.equal(chat.form.dataset.topic, 'expense');
    assert.equal(chat.button.disabled, false);
});

test('retains earlier messages and restores a failed question without duplicate submissions', async () => {
    let finish;
    const chat = setup(() => new Promise(resolve => { finish = resolve; }));
    const waiting = chat.send();
    await chat.send();
    assert.equal(chat.calls(), 1);
    finish({ok: false});
    await waiting;
    assert.equal(chat.output.children.length, 2);
    assert.equal(chat.output.children[1].className, 'cs-chat-message cs-chat-error');
    assert.equal(chat.input.value, 'Expense <script> marker');
    assert.equal(chat.button.disabled, false);
});

test('Enter sends but Shift+Enter and composition remain editable', async () => {
    const chat = setup(async () => ({ok: true, json: async () => ({data: {text: 'Guide', ai: false}})}));
    chat.key({key: 'Enter', shiftKey: true, preventDefault() { assert.fail('Shift+Enter must keep newline'); }});
    chat.key({key: 'Enter', isComposing: true, preventDefault() { assert.fail('Composition must not send'); }});
    assert.equal(chat.calls(), 0);
    chat.key({key: 'Enter', preventDefault() {}});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(chat.calls(), 1);
});
