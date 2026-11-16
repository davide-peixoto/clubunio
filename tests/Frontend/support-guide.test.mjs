import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const script = await readFile(new URL('../../public/js/clubunio-support.js', import.meta.url), 'utf8');

async function answer(data) {
    let submit;
    const element = tag => ({ tag, children: [], append(...nodes) { this.children.push(...nodes.filter(node => node !== '')); } });
    const output = element('output');
    const button = {};
    const form = {
        dataset: { ai: 'KI-Antwort', source: 'Quelle', knowledge: 'https://dev.example/platform/support?tab=knowledge' },
        elements: { body: { value: 'Spesen' } },
        querySelector: tag => tag === 'output' ? output : button,
        addEventListener: (_, handler) => { submit = handler; },
    };
    runInNewContext(script, {
        document: { querySelector: () => form, createElement: element, getElementById: () => null },
        location: { hash: '', pathname: '/platform/support' },
        FormData: class {},
        fetch: async () => ({ ok: true, json: async () => ({ data }) }),
        URL,
    });
    await submit({ preventDefault() {}, currentTarget: form });
    return output.children[0];
}

test('formats AI emphasis without evaluating HTML and retains the FAQ link', async () => {
    const result = await answer({ ai: true, text: 'Öffne **Spesen** und *prüfe* <script> **<img>**.', source: 'expense' });
    assert.equal(result.children.filter(node => typeof node !== 'string').map(node => node.tag).join(','), 'strong,em,strong,a');
    assert.equal(result.children.find(node => node.tag === 'strong').textContent, 'Spesen');
    assert.equal(result.children.filter(node => node.tag === 'strong')[1].textContent, '<img>');
    assert.ok(result.children.includes(' <script> '));
    assert.equal(result.children.at(-1).href, 'https://dev.example/platform/support?tab=knowledge#faq-expense');
});

test('preserves literal text in articles and fallback answers', async () => {
    const result = await answer({ ai: false, text: 'Original **Text** ohne Änderung.' });
    assert.equal(result.textContent, 'Original **Text** ohne Änderung.');
    assert.equal(result.children.length, 0);
});
