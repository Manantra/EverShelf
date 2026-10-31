#!/usr/bin/env node
'use strict';

/** Safari / standalone-PWA lifecycle and offline persistence simulation.
 * Executes EverShelf's actual queue/handler/replay functions in VM contexts.
 * Does not pretend to be real iOS WebKit or a real physical device.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function extract(start, end) {
    const a = app.indexOf(start);
    const b = app.indexOf(end, a + start.length);
    assert(a >= 0 && b > a, 'Missing queue source: ' + start);
    return app.slice(a, b);
}
const source = [
    "const _OFFLINE_QUEUE_KEY = 'test-queue';",
    extract('const _OFFLINE_QUEUE_LIMIT = 100;', 'function _offlineShoppingCacheGet()'),
    extract('async function _offlineQueuePush(action, body) {', '// ─── Offline API handler:'),
    extract('async function _handleOfflineApi(action, params, body) {', '// Optimistically update the cached inventory'),
    extract('async function _syncOfflineQueue() {', '// ─── Overlay show / hide'),
    'this.testApi = { push: _offlineQueuePush, handle: _handleOfflineApi, sync: _syncOfflineQueue, read: _offlineQueueGet };',
].join('\n');

function storage(shared = new Map()) {
    const state = { denyRead: false, denyWrite: false, blockWrite: null };
    return {
        state,
        shared,
        getItem(key) {
            if (state.denyRead) throw new Error('Safari storage denied');
            return shared.has(key) ? shared.get(key) : null;
        },
        setItem(key, value) {
            if (state.denyWrite || state.blockWrite?.(key, value)) {
                throw new Error('QuotaExceededError');
            }
            shared.set(key, String(value));
        },
    };
}
function webLocks() {
    let queue = Promise.resolve();
    const locks = {
        request(name, options, task) {
            assert.equal(name, 'evershelf-offline-queue');
            assert.equal(options.mode, 'exclusive');
            const running = queue.then(task);
            queue = running.catch(() => {});
            return running;
        },
    };
    return locks;
}
let counter = 0;
function browser(store, lock, apiImpl) {
    const actions = [], toasts = [], optimistic = [];
    const navigator = lock ? { locks: lock } : {};
    const scope = {
        localStorage: store,
        navigator,
        crypto: { randomUUID: () => 'op-' + ++counter },
        Date, JSON, Promise, Math, console,
        showToast: (message, level) => toasts.push({ message, level }),
        t: key => key,
        _renderOfflineBanner: () => {},
        _applyOptimisticUpdate: (action, body) => optimistic.push([action, body]),
        _applyShoppingOptimisticUpdate: () => {},
        api: async (action, params, method, body) => {
            actions.push({ action, body });
            return apiImpl(action, body, actions.length);
        },
    };
    vm.createContext(scope);
    vm.runInContext(source, scope);
    return { ...scope.testApi, actions, toasts, optimistic, store };
}
function queued(store) {
    const raw = store.shared.get('test-queue');
    return raw ? JSON.parse(raw) : [];
}
function sleepTick() {
    return new Promise(resolve => setImmediate(resolve));
}

async function testQuotaAndCapacity() {
    const st = storage(), h = browser(st, webLocks(), async () => ({ success: true }));
    st.state.denyWrite = true;
    let result = await h.handle('inventory_update', {}, { id: 1, quantity: 2 });
    assert.equal(result.success, false);
    assert.equal(result.error, 'offline_storage_unavailable');
    assert.equal(h.optimistic.length, 0, 'Never optimistic-update if storage rejected queue');
    st.state.denyWrite = false;
    const originals = Array.from({ length: 100 }, (_, i) => ({
        action: 'inventory_update', body: { id: i + 1, quantity: i }, _opId: 'old-' + i,
    }));
    st.setItem('test-queue', JSON.stringify(originals));
    result = await h.handle('inventory_update', {}, { id: 101, quantity: 10 });
    assert.equal(result.success, false);
    assert.equal(result.error, 'offline_queue_full');
    assert.equal(queued(st).length, 100);
    assert.equal(queued(st)[0]._opId, 'old-0', 'The oldest unsynced write is never evicted');
    assert.equal(h.optimistic.length, 0);

    st.setItem('test-queue', '{corrupt');
    result = await h.handle('inventory_update', {}, { id: 102, quantity: 8 });
    assert.equal(result.error, 'offline_storage_unavailable');
    assert.equal(st.getItem('test-queue'), '{corrupt', 'Malformed queue must not be overwritten');
    assert.equal(h.optimistic.length, 0);
}

async function testMultiTabQueueAndReplay() {
    const st = storage(), lock = webLocks(), apiCalls = [];
    const submit = async (action, body) => {
        apiCalls.push({ action, body });
        return action === 'shopping_remove'
            ? { success: true, removed: 1 } : { success: true };
    };
    const a = browser(st, lock, submit), b = browser(st, lock, submit);
    const [left, right] = await Promise.all([
        a.handle('inventory_update', {}, { id: 22, quantity: 3 }),
        b.handle('shopping_remove', {}, { name: 'Passierte' }),
    ]);
    assert.equal(left._queued, true);
    assert.equal(right._queued, true);
    assert.equal(queued(st).length, 2, 'Web Locks serialize both tabs without overwriting a queued edit');
    const [n1, n2] = await Promise.all([a.sync(), b.sync()]);
    assert.equal(n1 + n2, 2, 'Both tabs combined replay exactly the number of queued operations');
    assert.equal(apiCalls.length, 2, 'A second tab cannot double-submit the same queue');
    assert.equal(queued(st).length, 0);
}

async function testSuspensionCheckpointAndNewWrite() {
    const st = storage(), lock = webLocks();
    let releaseSecond;
    const h = browser(st, lock, async (action, body, nth) => {
        if (nth === 1) return { success: true };
        if (nth === 2) return new Promise(resolve => { releaseSecond = resolve; });
        return { success: true };
    });
    await h.push('inventory_update', { id: 41, quantity: 2 });
    await h.push('inventory_update', { id: 42, quantity: 3 });
    const syncing = h.sync();
    for (let i = 0; i < 15 && !releaseSecond; i++) await sleepTick();
    assert(releaseSecond, 'Second request has reached the network');
    assert.equal(queued(st).length, 1,
        'After first successful response the first operation is durably removed immediately');
    assert.equal(queued(st)[0].body.id, 42);
    const enqueuedDuringSync = h.push('inventory_update', { id: 43, quantity: 5 });
    await sleepTick();
    assert.equal(queued(st).length, 1,
        'Web Lock prevents a write from racing an in-flight queue checkpoint');
    releaseSecond({ success: false, error: 'network_unavailable' });
    assert.equal(await syncing, 1);
    assert.equal((await enqueuedDuringSync).success, true);
    assert.deepEqual(queued(st).map(op => op.body.id), [42, 43],
        'The failed request and the newly queued write both survive reconnect');
    assert.equal(queued(st)[0]._syncError, 'network_unavailable');
}

async function testConflictsAndBlockedDependents() {
    const st = storage(), lock = webLocks(), sendIds = [];
    const h = browser(st, lock, async (action, body) => {
        sendIds.push(body.id);
        return body.id === 81
            ? { success: false, error: 'inventory_conflict' }
            : { success: true };
    });
    await h.push('inventory_update', { id: 81, quantity: 5,
        expected_inventory: { quantity: 3 } });
    await h.push('inventory_update', { id: 81, quantity: 6,
        expected_inventory: { quantity: 5 } });
    await h.push('inventory_update', { id: 82, quantity: 9 });
    assert.equal(await h.sync(), 1);
    assert.deepEqual(sendIds, [81, 82],
        'Follow-up operation of conflicted row cannot overtake first failed change');
    const q = queued(st);
    assert.equal(q.length, 2);
    assert.equal(q[0]._needsReview, true);
    assert.equal(q[1].body.quantity, 6);
    await h.sync();
    assert.deepEqual(sendIds, [81, 82], '409 stays pending across further automatic replays');
    assert.equal(queued(st).length, 2);
}

async function testAckStorageFailureBlocksRetry() {
    const st = storage(), lock = webLocks();
    let count = 0;
    const h = browser(st, lock, async () => { count++; return { success: true }; });
    await h.push('inventory_add', { id: -3, product_id: 31, quantity: 1 });
    st.state.blockWrite = (_, raw) => JSON.parse(raw).length === 0;
    const synced = await h.sync();
    assert.equal(synced, 0, 'Do not claim acknowledged-and-removed if browser storage did not persist removal');
    assert.equal(count, 1);
    assert.equal(queued(st).length, 1);
    await h.sync();
    assert.equal(count, 1, 'Never immediately re-submit an ambiguously acknowledged write');
    assert(h.toasts.some(x => x.message === 'error.offline_storage_unavailable'));
}

async function testOfflineNewRowDependencies() {
    const st = storage(), actions = [];
    const h = browser(st, webLocks(), async (action, body) => {
        actions.push({ action, body });
        return action === 'inventory_add'
            ? { success: true, inventory_id: 9191 } : { success: true };
    });
    const added = await h.handle('inventory_add', {}, {
        product_id: 123, quantity: 2, location: 'dispensa',
    });
    assert.equal(added._queued, true);
    const tempId = queued(st)[0].body.id;
    assert.equal(typeof tempId, 'number');
    assert(tempId < 0, 'Offline added row gets a temporary negative ID');
    assert.equal(h.optimistic[0][1].id, tempId,
        'Optimistic display and durable queue refer to the same temporary row');
    await h.handle('inventory_update', {}, {
        id: tempId, quantity: 3, expected_inventory: { quantity: 2 },
    });
    assert.equal(await h.sync(), 2);
    assert.equal(actions[1].body.id, 9191,
        'Dependent edit uses server-issued ID after acknowledged inventory creation');
    assert.equal(queued(st).length, 0);
}

async function testNoopShoppingRemovalNotAck() {
    const st = storage();
    const h = browser(st, webLocks(), async () => ({ success: true, removed: 0 }));
    await h.handle('shopping_remove', {}, { name: 'Passierte' });
    assert.equal(await h.sync(), 0, 'Removed zero items is not a verified removal');
    const list = queued(st);
    assert.equal(list.length, 1);
    assert.equal(list[0]._needsReview, true);
    assert.equal(list[0]._syncError, 'shopping_remove_noop');
    await h.sync();
    assert.equal(h.actions.length, 1,
        'No-op shopping removal awaits review instead of retrying indefinitely');
}

async function testLegacyQueueAtQuota() {
    const st = storage(), lock = webLocks();
    const old = [
        { action: 'inventory_update', body: { id: 110, quantity: 2 }, ts: 100 },
        { action: 'inventory_update', body: { id: 111, quantity: 3 }, ts: 101 },
    ];
    st.setItem('test-queue', JSON.stringify(old));
    st.state.blockWrite = (_, content) =>
        JSON.parse(content).length >= 2
        && JSON.parse(content).some(item => item._opId);
    const h = browser(st, lock, async () => ({ success: true }));
    assert.equal(await h.sync(), 2,
        'Quota-full legacy queue can drain even when adding migration IDs cannot fit');
    assert.equal(queued(st).length, 0);
}

async function testOfflineBannerSurvivesConflicts() {
    const uiSource = extract(
        'async function _exitOfflineMode() {',
        '/** Show unsent actions;'
    );
    const banner = { style: { display: 'none' } };
    const label = { textContent: '', innerHTML: '' };
    const retry = { textContent: '', onclick: null };
    banner.querySelector = selector => selector === '.offline-banner-text'
        ? label : selector === '.offline-banner-retry' ? retry : null;
    let pending = [
        { action: 'inventory_update', _needsReview: true, body: { id: 7 } },
    ];
    const notifications = [];
    const ctx = {
        _offlineMode: true,
        _offlineQueueStorageBlocked: false,
        _offlineQueueReadError: false,
        document: {
            body: { classList: { remove() {} } },
            getElementById: id => id === 'offline-banner' ? banner : null,
        },
        _offlineQueueGet: () => pending,
        _syncOfflineQueue: async () => 0,
        _offlineCacheGet: () => [],
        _offlineReviewQueue: () => {},
        _heartbeatRetry: () => {},
        showToast: (message, level) => notifications.push({ message, level }),
        t: key => key,
    };
    vm.createContext(ctx);
    vm.runInContext(uiSource + '\nthis.testUi = { exit: _exitOfflineMode, render: _renderOfflineBanner };', ctx);
    await ctx.testUi.exit();
    assert.equal(banner.style.display, '',
        'Unresolved conflict keeps the online warning banner visible');
    assert.equal(label.textContent, 'error.offline_conflicts_pending');
    assert.equal(retry.textContent, 'error.offline_review');
    assert(notifications.some(n => n.level === 'warning'));
    pending = [];
    await ctx.testUi.exit();
    assert.equal(banner.style.display, 'none',
        'Banner disappears only when all queued operations are gone');
}

async function testSafariLockDenied() {
    const st = storage();
    const rejected = { request() { throw new Error('SecurityError: locks unavailable'); } };
    const h = browser(st, rejected, async () => ({ success: true }));
    const answer = await h.handle('inventory_update', {}, { id: 6, quantity: 4 });
    assert.equal(answer.success, false);
    assert.equal(answer.error, 'offline_storage_unavailable');
    assert.equal(h.optimistic.length, 0);
    assert.equal(queued(st).length, 0, 'No false queued acknowledgement if Web Locks rejected');
    assert.equal(await h.sync(), 0);
}

async function testFallbackWithinOneTab() {
    const st = storage();
    const h = browser(st, null, async () => ({ success: true }));
    await Promise.all(Array.from({ length: 15 }, (_, i) =>
        h.push('inventory_update', { id: i + 1, quantity: i })));
    assert.equal(queued(st).length, 15,
        'Older Safari/Lockdown local fallback still serializes writes inside one tab');
}

(async () => {
    await testQuotaAndCapacity();
    await testMultiTabQueueAndReplay();
    await testSuspensionCheckpointAndNewWrite();
    await testConflictsAndBlockedDependents();
    await testAckStorageFailureBlocksRetry();
    await testOfflineNewRowDependencies();
    await testNoopShoppingRemovalNotAck();
    await testLegacyQueueAtQuota();
    await testOfflineBannerSurvivesConflicts();
    await testSafariLockDenied();
    await testFallbackWithinOneTab();
    console.log('All Safari/PWA offline queue, suspension and multi-tab simulations passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
