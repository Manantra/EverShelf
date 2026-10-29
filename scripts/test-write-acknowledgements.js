#!/usr/bin/env node
'use strict';

/**
 * Regression tests for frontend write acknowledgements.
 *
 * Uses the actual functions extracted from app.js with stubbed API calls.
 * No network, real browser, or database is required.
 *
 * Run: node scripts/test-write-acknowledgements.js
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');

function extract(start, end) {
    const a = app.indexOf(start);
    assert.notEqual(a, -1, 'Missing start anchor: ' + start);
    const b = app.indexOf(end, a + start.length);
    assert.notEqual(b, -1, 'Missing end anchor: ' + end);
    return app.slice(a, b);
}

const replaySource = extract(
    'async function _syncOfflineQueue() {',
    '// ─── Overlay show / hide'
);
const removeSource = extract(
    'async function removeBringItem(idx) {',
    'let _shoppingTemplatesCache'
);

async function runReplay(ops, apiImpl) {
    let remaining;
    const calls = [];
    const context = {
        _offlineQueueGet: () => ops,
        _offlineQueueSet: q => { remaining = q; },
        api: async (action, params, method, body) => {
            calls.push({ action, body });
            return apiImpl(action, body, calls.length);
        },
    };
    const run = vm.runInNewContext(replaySource + '\n_syncOfflineQueue;', context);
    const synced = await run();
    return { synced, remaining, calls };
}

async function testReplay() {
    const update = { action: 'inventory_update', body: { id: 7, quantity: 2 } };

    let r = await runReplay([update], async () => ({ success: false, error: 'database_busy' }));
    assert.equal(r.synced, 0, 'A rejected write must not count as synchronized');
    assert.equal(r.remaining.length, 1, 'A rejected write must remain queued');

    r = await runReplay([update], async () => ({ success: true }));
    assert.equal(r.synced, 1);
    assert.equal(r.remaining.length, 0);

    r = await runReplay([update], async () => ({ success: true, _offline: true }));
    assert.equal(r.synced, 0, 'An optimistic offline response is not a server acknowledgement');
    assert.equal(r.remaining.length, 1);

    r = await runReplay([update], async () => { throw new Error('network offline'); });
    assert.equal(r.synced, 0);
    assert.equal(r.remaining.length, 1);

    r = await runReplay([update], async () => ({ success: false, error: 'invalid_response' }));
    assert.equal(r.synced, 0);
    assert.equal(r.remaining.length, 1);

    const create = { action: 'inventory_add', body: { id: -5, product_id: 7, quantity: 1 } };
    const dependent = { action: 'inventory_update', body: { id: -5, quantity: 2 } };
    r = await runReplay([create, dependent], async (action) => (
        action === 'inventory_add'
            ? { success: false, error: 'database_busy' }
            : { success: true }
    ));
    assert.equal(r.synced, 0, 'Do not replay dependent writes after a failed create');
    assert.equal(r.remaining.length, 2, 'Both the create and its dependent edit must be retained');
    assert.equal(r.calls.length, 1, 'Do not send an unresolved negative ID to the server');

    r = await runReplay([create, dependent], async (action) => (
        action === 'inventory_add'
            ? { success: true, id: 91 }
            : { success: true }
    ));
    assert.equal(r.synced, 2);
    assert.equal(r.remaining.length, 0);
    assert.equal(r.calls[1].body.id, 91, 'Resolve temporary IDs only after confirmed create');

    r = await runReplay([create, dependent], async (action) => (
        action === 'inventory_add'
            ? { success: true, id: 91 }
            : { success: false, error: 'database_busy' }
    ));
    assert.equal(r.synced, 1, 'A failed follow-up edit is not synchronized');
    assert.equal(r.remaining.length, 1);
    assert.equal(r.remaining[0].body.id, 91, 'Retain the real ID when an edit fails after a successful create');
    const retried = await runReplay(r.remaining, async () => ({ success: true }));
    assert.equal(retried.synced, 1, 'Persisted real ID must replay on the next attempt');
    assert.equal(retried.remaining.length, 0);
}

async function runRemove(reply) {
    const shoppingItems = [{ name: 'Passierte', rawName: 'Passierte tomaten' }];
    const toasts = [];
    let reloads = 0;
    const context = {
        shoppingItems,
        shoppingListUUID: 'internal-list',
        loadShoppingList: async () => { reloads++; },
        document: { getElementById: () => null },
        api: async () => reply,
        _markBringPurchased: () => {},
        _offlineShoppingCacheSet: () => {},
        _offlineQueueGet: () => [{ action: 'shopping_remove' }],
        renderShoppingItems: () => {},
        showToast: (text, level) => toasts.push({ text, level }),
        t: key => key,
        logOperation: () => {},
        loadShoppingCount: () => {},
    };
    const remove = vm.runInNewContext(removeSource + '\nremoveBringItem;', context);
    await remove(0);
    return { shoppingItems, toasts, reloads };
}

async function testRemove() {
    let r = await runRemove({ success: true, removed: 0 });
    assert.equal(r.shoppingItems.length, 1, 'Do not hide an item when the server removed nothing');
    assert.equal(r.toasts.at(-1)?.level, 'error', 'Do not show a success toast for a no-op');

    r = await runRemove({ success: true, removed: 1 });
    assert.equal(r.shoppingItems.length, 0);
    assert.equal(r.toasts.at(-1)?.level, 'success');

    r = await runRemove({ success: true, removed: true }); // Bring! transport
    assert.equal(r.shoppingItems.length, 0);

    r = await runRemove({ success: false, error: 'database_busy' });
    assert.equal(r.shoppingItems.length, 1);
    assert.equal(r.toasts.at(-1)?.level, 'error');

    r = await runRemove({ success: true, _offline: true, _queued: true });
    assert.equal(r.shoppingItems.length, 0, 'Optimistic offline removal may update local state');
    assert.notEqual(r.toasts.at(-1)?.level, 'success', 'Queued is not persisted');
}

(async () => {
    await testReplay();
    await testRemove();
    console.log('All write acknowledgement regression tests passed.');
})().catch(err => {
    console.error(err);
    process.exitCode = 1;
});
