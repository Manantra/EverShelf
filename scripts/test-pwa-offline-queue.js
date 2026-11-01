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
    const queuedOpId = queued(st)[0]._opId;
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
    assert.equal(actions[0].body._offline_op_id, queuedOpId,
        'Inventory add carries its persisted queue identifier as server receipt key');
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
    // The real bug: discard calls _renderOfflineBanner() directly, not
    // _exitOfflineMode(). The empty queue must hide the previous banner.
    banner.style.display = '';
    ctx.testUi.render();
    assert.equal(banner.style.display, 'none',
        'An online render hides an old banner after the final discard');
    // On the other hand, a storage failure should stay visible even if
    // no queue entries could be read.
    ctx._offlineQueueStorageBlocked = true;
    ctx.testUi.render();
    assert.equal(banner.style.display, '',
        'A blocked Safari storage write still keeps the warning visible');
}

async function testLastConflictDiscardClosesReviewModal() {
    const reviewSource = extract(
        'function _offlineQuantityResolutionPreview(op, current) {',
        'async function _syncOfflineQueue() {'
    );
    const st = storage();
    st.setItem('test-queue', JSON.stringify([{
        action: 'inventory_update', body: { id: 42, quantity: 7 },
        _needsReview: true, _opId: 'stale-iphone-conflict',
    }]));
    const overlay = { style: { display: 'none' } };
    const content = {
        innerHTML: '',
        querySelectorAll: () => [],
        querySelector: () => null,
    };
    let closes = 0;
    let renders = 0;
    let confirmations = 0;
    const ctx = {
        _offlineMode: false,
        _offlineQueueStorageBlocked: false,
        _offlineQueueReadError: false,
        _offlineWithQueueLock: async fn => fn(),
        _offlineQueueReadStrict: () => JSON.parse(st.getItem('test-queue') || '[]'),
        _offlineQueueGet: () => JSON.parse(st.getItem('test-queue') || '[]'),
        _offlineQueueSet: q => st.setItem('test-queue', JSON.stringify(q)),
        _offlineNewOpId: () => 'migrated',
        api: async () => ({
            success: true,
            inventory: { id: 42, product_id: 3, quantity: 8, revision: 1,
                name: 'Safari test', unit: 'conf', location: 'dispensa' },
        }),
        _renderOfflineBanner: () => { renders++; },
        document: { getElementById: id => ({
            'modal-content': content, 'modal-overlay': overlay,
        })[id] },
        navigator: { clipboard: { writeText: async () => {} } },
        escapeHtml: value => String(value),
        showToast: () => {},
        confirm: () => { confirmations++; return true; },
        closeModal: () => { closes++; overlay.style.display = 'none'; },
        t: key => key,
    };
    vm.createContext(ctx);
    vm.runInContext(reviewSource + '\nthis.reviewTest = { review: _offlineReviewQueue, discard: _offlineDiscardQueuedOp };', ctx);
    await ctx.reviewTest.review();
    assert.equal(overlay.style.display, 'flex', 'Conflict can be reviewed before user acts');
    assert(content.innerHTML.includes('data-offline-discard'),
        'The review includes the discard control for the pending operation');
    assert.equal(closes, 0, 'An actual conflict must never close itself');
    await ctx.reviewTest.discard('stale-iphone-conflict');
    assert.equal(confirmations, 1, 'Discarding a pending operation requires explicit confirmation');
    assert.equal(queued(st).length, 0, 'Only the explicitly discarded conflict is removed');
    assert.equal(closes, 1, 'Discarding the last conflict automatically closes its review modal');
    assert.equal(overlay.style.display, 'none');
    assert(renders >= 2, 'The online pending banner is updated after final discard');
    await ctx.reviewTest.review();
    assert.equal(overlay.style.display, 'none',
        'Opening the empty review must not show a misleading copy-only modal');
    assert.equal(queued(st).length, 0);
}

async function testManualAndAutomaticOfflineBannerAreIdentical() {
    // Execute the real overlay, offline-entry and banner functions. The iOS
    // bug surfaced only on clicking "Continue offline"; previously its
    // full-screen z-index 300000 overlay lingered over the banner for 450 ms.
    const uiSource = [
        'let _offlineMode = false;',
        'let _networkDown = false;',
        'let _networkFailCount = 0;',
        'let _offlineBannerTimer = null;',
        'let _continueBtnTimer = null;',
        'const _OFFLINE_MODE_DELAY_MS = 8000;',
        extract('function _enterOfflineMode() {', 'async function _exitOfflineMode() {'),
        extract('function _renderOfflineBanner(loading = false) {', '/** Show unsent actions;'),
        extract('function _showNetworkOverlay() {', '// Ping the server;'),
        'this.offlineEntryTest = { show: _showNetworkOverlay, enter: _enterOfflineMode,'
            + ' offline: () => _offlineMode };',
    ].join('\n');

    function simulate(method) {
        const timers = new Map(), classes = new Set();
        let nextTimer = 0, refreshes = 0;
        const overlay = {
            style: { display: 'none' },
            classList: {
                add: (...args) => args.forEach(x => classes.add(x)),
                remove: (...args) => args.forEach(x => classes.delete(x)),
                contains: x => classes.has(x),
            },
        };
        const continueBtn = { style: { display: 'none' },
            classList: { add() {}, remove() {} }, textContent: '' };
        const bannerText = { textContent: '', innerHTML: '' };
        const bannerRetry = { textContent: '', onclick: null };
        const banner = {
            style: { display: 'none' },
            querySelector: selector =>
                selector === '.offline-banner-text' ? bannerText
                    : selector === '.offline-banner-retry' ? bannerRetry : null,
        };
        const root = {
            _offlineQueueGet: () => [],
            _offlineQueueReadError: false,
            _offlineQueueStorageBlocked: false,
            _offlineCacheGet: () => [{ quantity: 13 }],
            _heartbeatRetry: () => {},
            t: key => ({
                'error.offline_cache_ready': 'Offline — {n} Produkte im Cache',
                'error.offline_reading_cache': 'Lese aus lokalem Cache',
                'error.offline_continue': 'Offline fortfahren',
            })[key] || key,
            document: {
                body: { classList: {
                    add: x => classes.add('body:' + x),
                    remove: x => classes.delete('body:' + x),
                } },
                getElementById: id => ({
                    'network-error-overlay': overlay,
                    'net-error-continue-btn': continueBtn,
                    'offline-banner': banner,
                    'net-error-title': { textContent: '' },
                    'net-error-subtitle': { textContent: '' },
                    'net-error-icon': { textContent: '' },
                    'net-error-status': { textContent: '' },
                })[id] || null,
            },
            refreshCurrentPage: () => { refreshes++; },
            requestAnimationFrame: fn => fn(),
            setTimeout: (fn, ms) => {
                const id = ++nextTimer;
                timers.set(id, { fn, ms });
                return id;
            },
            clearTimeout: id => timers.delete(id),
        };
        vm.createContext(root);
        vm.runInContext(uiSource, root);
        root.offlineEntryTest.show();
        assert.equal(overlay.style.display, 'flex');
        if (method === 'manual') {
            const timer = [...timers].find(([, x]) => x.ms === 3000);
            assert(timer);
            timers.delete(timer[0]);
            timer[1].fn();
            assert.equal(continueBtn.style.display, '',
                'User can choose Continue offline after three seconds');
            root.offlineEntryTest.enter();
        } else {
            const timer = [...timers].find(([, x]) => x.ms === 8000);
            assert(timer);
            timers.delete(timer[0]);
            timer[1].fn();
        }
        assert.equal(root.offlineEntryTest.offline(), true,
            method + ': entered cache mode');
        assert.equal(overlay.style.display, 'none',
            method + ': full-screen overlay removed synchronously');
        assert.equal(classes.has('visible'), false,
            method + ': no overlay transition may obscure cache banner');
        assert.equal(banner.style.display, '',
            method + ': banner immediately visible');
        assert.equal(classes.has('body:offline-mode'), true,
            method + ': body explicitly shows offline mode');
        assert.equal(refreshes, 1, method + ': only one cache refresh');
        const timer = [...timers].find(([, x]) => x.ms === 600);
        assert(timer, method + ': deferred cache-ready label');
        timers.delete(timer[0]);
        timer[1].fn();
        assert.equal(bannerText.innerHTML, 'Offline — 1 Produkte im Cache',
            method + ': unambiguous offline status and correct cached count');
        assert.equal(banner.style.display, '',
            method + ': banner stays visible after cache refresh');
        assert.equal([...timers.values()].some(x => x.ms === 450), false,
            method + ': no delayed overlay hide after entering offline');
        return { label: bannerText.innerHTML, visible: banner.style.display };
    }
    assert.deepEqual(simulate('manual'), simulate('automatic'),
        'Manual and automatic offline entry must paint the same banner');
}

async function testQuantityResolutionSafetyGate() {
    const fn = extract('function _offlineQuantityResolutionPreview(op, current) {',
        '/** Show user-readable current-vs-offline values;');
    const ctx = {};
    vm.createContext(ctx);
    vm.runInContext(fn + '\nthis.preview = _offlineQuantityResolutionPreview;', ctx);
    const current = {
        id: 11, product_id: 9, quantity: 8, revision: 3,
        location: 'dispensa', unit: 'conf', package_size: 690,
        package_unit: 'g', expiry_date: null, vacuum_sealed: 0,
        expiry_user_set: 0,
    };
    const op = {
        action: 'inventory_update', _needsReview: true,
        body: { id: 11, product_id: 9, quantity: 7, location: 'dispensa',
            unit: 'conf', package_size: 690, package_unit: 'g',
            expected_inventory: { quantity: 6, location: 'dispensa' } },
    };
    let preview = ctx.preview(op, current);
    assert.equal(preview.original, 6);
    assert.equal(preview.wanted, 7);
    assert.equal(preview.latest, 8);
    assert.equal(preview.combined, 9);
    assert.equal(preview.canDelta, true);
    assert.equal(ctx.preview({ ...op, body: { ...op.body,
        product_edit: { name: 'Other' } } }, current), null,
        'A combined rename cannot lose its non-quantity changes');
    assert.equal(ctx.preview({ ...op, body: { ...op.body,
        expiry_date: '2030-01-01' } }, current), null,
        'An edited expiry cannot be silently dropped');
    assert.equal(ctx.preview(op, { ...current, location: 'frigo' }), null,
        'A server-side location move is not a simple quantity-only edit');
    assert.equal(ctx.preview({ ...op, body: { ...op.body, quantity: 1,
        expected_inventory: { quantity: 6, location: 'dispensa' } } },
        { ...current, quantity: 3 }).canDelta, false,
        'Negative rebasing is not offered');
    assert.equal(ctx.preview({ ...op, body: { ...op.body,
        package_size: 100 } }, current), null,
        'Changed package settings are not silently dropped');
}

async function testDependentWritesStayPendingAfterKeepServer() {
    const snippet = extract('function _offlineRemoveResolvedOp(queue, opId) {',
        'async function _offlineDiscardQueuedOp(opId, keepServer = false) {');
    const ctx = {};
    vm.createContext(ctx);
    vm.runInContext(snippet + '\nthis.remove = _offlineRemoveResolvedOp;', ctx);
    const q = [
        { _opId: 'first', action: 'inventory_update', body: { id: 42 } },
        { _opId: 'second', action: 'inventory_update', body: { id: 42 } },
        { _opId: 'unrelated', action: 'inventory_update', body: { id: 51 } },
    ];
    const remaining = ctx.remove(q, 'first');
    assert.equal(remaining.length, 2);
    assert.equal(remaining[0]._needsReview, true,
        'Dependent later edit cannot silently replay against a new server base');
    assert.equal(remaining[0]._syncError, 'dependent_conflict');
    assert.equal(remaining[1]._needsReview, undefined,
        'Unrelated product still can synchronize normally');
}

async function testDurableConflictResolutionAfterLostResponse() {
    const reviewSource = extract(
        'function _offlineQuantityResolutionPreview(op, current) {',
        'async function _syncOfflineQueue() {'
    );
    const st = storage(), calls = [], toasts = [];
    st.setItem('test-queue', JSON.stringify([{
        action: 'inventory_update', _needsReview: true,
        _syncError: 'inventory_conflict', _opId: 'offline-stale',
        body: { id: 42, product_id: 3, quantity: 7,
            expected_inventory: { quantity: 6, location: 'dispensa' },
            location: 'dispensa', unit: 'conf' },
    }]));
    const snapshot = { id: 42, product_id: 3, quantity: 8, revision: 4,
        name: 'Test', unit: 'conf', location: 'dispensa' };
    const overlay = { style: { display: 'none' } };
    const content = { innerHTML: '', querySelectorAll: () => [],
        querySelector: () => null };
    let committed = 0, postCount = 0, refreshCount = 0;
    const ctx = {
        _offlineMode: false, _offlineQueueStorageBlocked: false,
        _offlineQueueReadError: false,
        _offlineWithQueueLock: async fn => fn(),
        _offlineQueueReadStrict: () => JSON.parse(st.getItem('test-queue') || '[]'),
        _offlineQueueSet: q => st.setItem('test-queue', JSON.stringify(q)),
        _offlineQueueGet: () => JSON.parse(st.getItem('test-queue') || '[]'),
        _offlineNewOpId: () => 'durable-resolution-id',
        _renderOfflineBanner: () => {},
        document: { getElementById: id => ({
            'modal-content': content, 'modal-overlay': overlay,
        })[id] },
        navigator: { clipboard: { writeText: async () => {} } },
        escapeHtml: value => String(value),
        showToast: (message, type) => toasts.push({ message, type }),
        confirm: () => true,
        closeModal: () => { overlay.style.display = 'none'; },
        refreshCurrentPage: () => { refreshCount++; },
        t: key => key,
        api: async (action, params, method, payload) => {
            if (action === 'inventory_conflict_snapshot') {
                return { success: true, inventory: snapshot };
            }
            assert.equal(action, 'inventory_conflict_resolve');
            assert.equal(method, 'POST');
            postCount++;
            calls.push(JSON.parse(JSON.stringify(payload)));
            // The first POST has already committed, but the HTTP response is
            // lost to iOS suspension. A second POST must carry the SAME key.
            if (postCount === 1) { committed++; throw Error('response lost'); }
            return { success: true, idempotent_replay: true,
                quantity: 9, id: 42, revision: 5 };
        },
    };
    vm.createContext(ctx);
    vm.runInContext(reviewSource
        + '\nthis.testResolution = { resolve: _offlineResolveQueuedOp,'
        + ' keep: _offlineDiscardQueuedOp };', ctx);
    await ctx.testResolution.resolve('offline-stale', 'delta');
    assert.equal(committed, 1);
    assert.equal(queued(st).length, 1, 'Missing ACK must keep the pending change');
    assert.equal(queued(st)[0]._resolution._offline_op_id, 'durable-resolution-id',
        'Resolution intent/receipt key is saved BEFORE network POST');
    await ctx.testResolution.resolve('offline-stale', 'delta');
    assert.equal(postCount, 2);
    assert.deepEqual(calls[0], calls[1],
        'Only the original identical resolution is ever retried');
    assert.equal(queued(st).length, 0, 'Confirmed replay removes only its queued operation');
    assert.equal(refreshCount, 1);
    assert.equal(committed, 1, 'Recovered response did not double-apply a stock delta');

    // Keep-server is a local-only action, even after a conflict.
    st.setItem('test-queue', JSON.stringify([{
        action: 'inventory_update', _needsReview: true,
        _syncError: 'inventory_conflict', _opId: 'keep-later',
        body: { id: 42, product_id: 3, quantity: 7,
            expected_inventory: { quantity: 6 } },
    }]));
    const callsBefore = postCount;
    await ctx.testResolution.keep('keep-later', true);
    assert.equal(queued(st).length, 0);
    assert.equal(postCount, callsBefore, 'Keep server must not send any stock write');
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
    await testLastConflictDiscardClosesReviewModal();
    await testManualAndAutomaticOfflineBannerAreIdentical();
    await testQuantityResolutionSafetyGate();
    await testDependentWritesStayPendingAfterKeepServer();
    await testDurableConflictResolutionAfterLostResponse();
    await testSafariLockDenied();
    await testFallbackWithinOneTab();
    console.log('All Safari/PWA offline queue, suspension and multi-tab simulations passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
