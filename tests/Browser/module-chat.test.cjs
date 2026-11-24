const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor() { this.children = []; this.attributes = {}; this.value = ''; }
    append(...items) { this.children.push(...items); }
    replaceChildren(...items) { this.children = items; }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener() {}
}
function fixture(modern) {
    const elements = Object.fromEntries(['[data-conversations]','[data-search]','[data-chat-overview]','[data-unread-filter]','[data-action=contacts]'].map(key => [key, new Element]));
    const context = { document: { createElement: () => new Element, querySelectorAll: () => [], addEventListener() {}, readyState: 'loading' } };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('public/js/clubunio-chat.js', 'utf8').replace('export class ClubUnioChat', 'class ClubUnioChat') + '\nthis.Chat = ClubUnioChat;', context);
    const chat = Object.create(context.Chat.prototype);
    Object.assign(chat, { root: { querySelector: key => elements[key] }, chats: [{ id: 1, title: '<img onerror=attack>', unread: 0 }, { id: 2, title: 'Team Alpha', unread: 3 }], cap: { send: false }, labels: {}, moduleUi: modern ? { conversations: 'Chats', unread: 'Unread', empty_unread: 'Empty' } : null, unreadOnly: false });
    return { chat, elements, list: elements['[data-conversations]'] };
}
test('unread filtering composes with search and preserves counts and safe text', () => {
    const { chat, elements, list } = fixture(true);
    chat.renderList();
    assert.equal(list.children.length, 2);
    assert.equal(list.children[0].children[1].textContent, '<img onerror=attack>');
    assert.equal(elements['[data-chat-overview]'].children[1].textContent, 'Unread: 3');
    chat.unreadOnly = true; chat.renderList();
    assert.equal(list.children.length, 1);
    assert.equal(list.children[0].children[1].textContent, 'Team Alpha');
    assert.equal(elements['[data-unread-filter]'].attributes['aria-pressed'], 'true');
    elements['[data-search]'].value = 'other'; chat.renderList();
    assert.equal(list.children[0].textContent, 'Empty');
    assert.equal(elements['[data-action=contacts]'].hidden, true);
});
test('classic mode keeps the original list without overview or avatars', () => {
    const { chat, elements, list } = fixture(false);
    chat.renderList();
    assert.equal(list.children[0].children.length, 1);
    assert.equal(elements['[data-chat-overview]'].children.length, 0);
});
