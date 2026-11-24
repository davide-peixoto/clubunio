import test from 'node:test';
import assert from 'node:assert/strict';
import { menuMatches } from '../../resources/js/navigation-search.mjs';

const items = [{ label: 'Änderungsanträge', group: 'Mitglieder', url: '/changes' }, { label: 'Teamplanung', group: 'Sport', url: '/planning' }];
test('menu search matches accents, groups and multiple words', () => {
    assert.deepEqual(menuMatches(items, 'mitglieder anderung'), [items[0]]);
    assert.deepEqual(menuMatches(items, 'SPORT team'), [items[1]]);
    assert.deepEqual(menuMatches(items, 'not-present'), []);
    assert.deepEqual(menuMatches(items, ''), items);
});
