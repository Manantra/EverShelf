#!/usr/bin/env node
'use strict';

/** Replay actual EverShelf PWA queue against an isolated, real SQLite API.
 * Two device sessions: offline stale writer and online winner. Uses no
 * production data and refuses non-loopback or unmarked server roots.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const { randomUUID } = require('node:crypto');

const rootArg = process.argv.indexOf('--sandbox-root');
const urlArg = process.argv.indexOf('--base-url');
assert(rootArg !== -1 && urlArg !== -1, 'Need --sandbox-root and --base-url');
const root = path.resolve(process.argv[rootArg + 1]);
const base = process.argv[urlArg + 1].replace(/\/$/, '');
assert(/^http:\/\/(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?$/.test(base),
    'Refusing non-loopback URL');
assert(fs.readFileSync(path.join(root, '.env'), 'utf8').includes('EVERSHELF_SANDBOX=1'),
    'Refusing unmarked or production database');
assert(fs.existsSync(path.join(root, 'data/evershelf.db')), 'Missing isolated DB');

let apiCount = 0;
async function request(action, body = null, suffix = '') {
    apiCount++;
    const response = await fetch(base + '/api/index.php?action=' + action + suffix, {
        method: body === null ? 'GET' : 'POST',
        headers: body === null ? {} : {
            'Content-Type': 'application/json', 'X-EverShelf-Request': '1',
        },
        body: body === null ? undefined : JSON.stringify(body),
    });
    return [response.status, await response.json()];
}

const source = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function extract(a, b) {
    const i = source.indexOf(a);
    const j = source.indexOf(b, i + a.length);
    assert(i >= 0 && j > i, 'Missing queue helper: ' + a);
    return source.slice(i, j);
}
const queueSource = [
    "const _OFFLINE_QUEUE_KEY = 'test-offline-replay';",
    extract('const _OFFLINE_QUEUE_LIMIT = 100;', 'function _offlineShoppingCacheGet()'),
    extract('async function _offlineQueuePush(action, body) {', '// ─── Offline API handler:'),
    extract('async function _syncOfflineQueue() {', '// ─── Overlay show / hide'),
    'this.queue = { push: _offlineQueuePush, sync: _syncOfflineQueue, get: _offlineQueueGet };',
].join('\n');

const store = new Map();
const localStorage = {
    getItem: key => store.get(key) ?? null,
    setItem: (key, val) => { store.set(key, String(val)); },
};
let nextOp = 0;
let locked = Promise.resolve();
const navigator = {
    locks: {
        request(name, opts, fn) {
            assert.equal(name, 'evershelf-offline-queue');
            const result = locked.then(fn);
            locked = result.catch(() => {});
            return result;
        },
    },
};
function browser(afterServerReply = null) {
    const logs = [];
    const ctx = {
        localStorage, navigator,
        crypto: { randomUUID },
        _renderOfflineBanner: () => {},
        showToast: (message, type) => logs.push({ message, type }),
        t: key => key,
        api: async (action, _params, _method, body) => {
            const [, result] = await request(action, body);
            return afterServerReply ? afterServerReply(action, result) : result;
        },
    };
    vm.createContext(ctx);
    vm.runInContext(queueSource, ctx);
    return { ...ctx.queue, logs };
}
function sql(productId, inventoryId) {
    const program = [
        'import sqlite3,json,sys',
        'db=sqlite3.connect(sys.argv[1]); p=int(sys.argv[2]); i=int(sys.argv[3])',
        "name=db.execute('SELECT name FROM products WHERE id=?',(p,)).fetchone()[0]",
        "qty=db.execute('SELECT quantity FROM inventory WHERE id=?',(i,)).fetchone()[0]",
        'ledger=db.execute("SELECT COUNT(*) FROM transactions WHERE product_id=? AND notes=\'[Manual correction]\'",(p,)).fetchone()[0]',
        "health=db.execute('PRAGMA quick_check').fetchone()[0]",
        'print(json.dumps([name,qty,ledger,health]))',
    ].join('\n');
    return JSON.parse(execFileSync('python3', ['-c', program,
        path.join(root, 'data/evershelf.db'), String(productId), String(inventoryId)],
    { encoding: 'utf8' }));
}

(async () => {
    const label = 'OfflineConflictAudit ' + Date.now().toString(36);
    let [status, created] = await request('product_save', {
        name: label, name_user_set: 1, unit: 'conf',
        default_quantity: 690, package_unit: 'g', category: 'conserve',
    });
    assert.equal(status, 200);
    assert.equal(created.success, true);
    const pid = created.id;
    [status, created] = await request('inventory_add', {
        product_id: pid, quantity: 2, location: 'dispensa', unit: 'conf',
    });
    assert.equal(status, 200);
    [status, created] = await request('inventory_list');
    assert.equal(status, 200);
    const item = created.inventory.find(row => row.product_id === pid);
    assert(item);
    const iid = item.id;

    const offline = browser();
    const cachedSnapshot = {
        id: iid, product_id: pid, quantity: 5, location: 'dispensa',
        expected_inventory: { quantity: 2, location: 'dispensa' },
        product_edit: {
            name: label + ' Offline', expected_name: label,
        },
    };
    assert.equal((await offline.push('inventory_update', cachedSnapshot)).success, true);
    assert.equal(offline.get().length, 1);

    // Device B changes both pieces while device A is away.
    [status, created] = await request('inventory_update', {
        id: iid, product_id: pid, quantity: 3, location: 'dispensa',
        expected_inventory: { quantity: 2, location: 'dispensa' },
        product_edit: { name: label + ' Online', expected_name: label },
    });
    assert.equal(status, 200);
    assert.equal(created.success, true);
    assert.deepEqual(sql(pid, iid), [label + ' Online', 3, 1, 'ok']);

    const replayed = await offline.sync();
    assert.equal(replayed, 0, 'Stale offline write must not receive success');
    let q = offline.get();
    assert.equal(q.length, 1);
    assert.equal(q[0]._needsReview, true);
    assert(['inventory_conflict', 'product_conflict'].includes(q[0]._syncError));
    assert.deepEqual(sql(pid, iid), [label + ' Online', 3, 1, 'ok'],
        'Stale offline replay cannot overwrite online winner or ledger');

    // Simulated iOS PWA reload: new VM, same persisted localStorage.
    const relaunched = browser();
    const beforeRequests = apiCount;
    assert.equal(await relaunched.sync(), 0);
    assert.equal(apiCount, beforeRequests,
        'After Safari reload, unresolved stale edit must not replay automatically');
    assert.equal(relaunched.get().length, 1,
        'Conflict remains inspectable and cannot vanish on reload');

    // A newer, unrelated product may still synchronize while the blocked
    // original operation remains unresolved.
    let [code, other] = await request('product_save', {
        name: label + ' Other', unit: 'conf', default_quantity: 690,
        package_unit: 'g', category: 'conserve',
    });
    assert.equal(code, 200);
    const otherPid = other.id;
    [code, other] = await request('inventory_add', {
        product_id: otherPid, quantity: 1, location: 'dispensa', unit: 'conf',
    });
    assert.equal(code, 200);
    [code, other] = await request('inventory_list');
    const otherId = other.inventory.find(row => row.product_id === otherPid).id;
    assert.equal((await relaunched.push('inventory_update', {
        id: otherId, quantity: 2, expected_inventory: { quantity: 1 },
    })).success, true);
    assert.equal(await relaunched.sync(), 1,
        'Different product can still sync while conflict remains');
    assert.equal(relaunched.get().length, 1);
    assert.equal(relaunched.get()[0]._needsReview, true);
    assert.equal(sql(otherPid, otherId)[1], 2);

    // Add offline, then edit its temporary row before either request reached
    // the server. The real endpoint returns inventory_id, not id.
    [code, other] = await request('product_save', {
        name: label + ' OfflineNew', unit: 'conf', default_quantity: 690,
        package_unit: 'g', category: 'conserve',
    });
    assert.equal(code, 200);
    const newPid = other.id;
    const temporary = -34991372;
    assert.equal((await relaunched.push('inventory_add', {
        id: temporary, product_id: newPid, quantity: 2,
        location: 'dispensa', unit: 'conf',
    })).success, true);
    assert.equal((await relaunched.push('inventory_update', {
        id: temporary, quantity: 3,
        expected_inventory: { quantity: 2, location: 'dispensa' },
    })).success, true);
    assert.equal(await relaunched.sync(), 2,
        'Offline new row and subsequent edit both replay with acknowledged server inventory_id');
    [code, other] = await request('inventory_list');
    const newRow = other.inventory.find(row => row.product_id === newPid);
    assert(newRow && newRow.id > 0);
    assert.deepEqual(sql(newPid, newRow.id).slice(1), [3, 1, 'ok']);
    assert.equal(relaunched.get().length, 1,
        'Previously conflicted operation is still pending after other successful replays');
    // Server commits an offline add but the simulated Safari client loses the
    // response. Automatic retry would double the inventory; block for review.
    [code, other] = await request('product_save', {
        name: label + ' LostAck', unit: 'conf', default_quantity: 690,
        package_unit: 'g', category: 'conserve',
    });
    assert.equal(code, 200);
    const uncertainPid = other.id;
    let loseOnce = true;
    const uncertain = browser((action, response) => {
        if (action === 'inventory_add' && loseOnce) {
            loseOnce = false;
            throw new Error('Safari lost response AFTER server commit');
        }
        return response;
    });
    assert.equal((await uncertain.push('inventory_add', {
        id: -100031, product_id: uncertainPid, quantity: 1,
        location: 'dispensa', unit: 'conf',
    })).success, true);
    assert.equal(await uncertain.sync(), 0);
    assert.equal(uncertain.get()[0]._needsReview, true,
        'Missing acknowledgement needs explicit review');
    [code, other] = await request('inventory_list');
    const uncertainRow = other.inventory.find(row => row.product_id === uncertainPid);
    assert(uncertainRow && uncertainRow.quantity === 1);
    const retryAfterReload = browser();
    const beforeResume = apiCount;
    assert.equal(await retryAfterReload.sync(), 0);
    assert.equal(apiCount, beforeResume,
        'A PWA restart must not automatically duplicate an uncertain add');
    [code, other] = await request('inventory_list');
    assert.equal(other.inventory.find(row => row.product_id === uncertainPid).quantity, 1);
    // A user-approved retry uses the persisted operation key. Even though
    // the FIRST call really committed, this must return its receipt instead
    // of adding another package.
    const retryOp = retryAfterReload.get().find(op => op.body.product_id === uncertainPid);
    assert(retryOp && retryOp._opId);
    [code, other] = await request('inventory_add', {
        ...retryOp.body, _offline_op_id: retryOp._opId,
    });
    assert.equal(code, 200);
    assert.equal(other.success, true);
    assert.equal(other.idempotent_replay, true);
    [code, other] = await request('inventory_list');
    assert.equal(other.inventory.find(row => row.product_id === uncertainPid).quantity, 1,
        'Retrying same offline operation ID does not add stock twice');
    console.log('Actual HTTP/SQLite offline conflicts, lost replies, and idempotent user retry passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
