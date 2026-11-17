#!/usr/bin/env node
'use strict';
// Issue #10: check the real settings functions with delayed/rejected API acknowledgements.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function section(start, end) {
    const a = app.indexOf(start);
    const b = app.indexOf(end, a + start.length);
    assert.ok(a !== -1 && b > a, 'missing source boundary: ' + start);
    return app.slice(a, b);
}
const source = section('let _settingsCache = null;', 'async function syncSettingsFromDB() {');
const syncSource = section('async function syncSettingsFromDB() {', '/**\n * Keys the server always emits');
const saveSource = section('async function saveSettings() {', '// ── Calendar (ICS expiry feed)');
assert.ok(/async function loadSettingsUI\(\)[\s\S]*?_watchSettingsFormEdits\(\)/.test(app), 'the settings panel must watch user edits');
assert.ok(/if \(_settingsFormLoadIsCurrent\(loadRevision, loadFormEditRevision\)\)/.test(app), 'late settings-panel responses must be guarded');

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}
const tick = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };
function harness() {
    const requests = [];
    let nextResponse = () => Promise.resolve({ success: true });
    const timers = new Map();
    let clockId = 0;
    const localStorage = { setItem() {}, getItem() { return null; } };
    const ctx = vm.createContext({
        localStorage,
        setTimeout(fn) { const id = ++clockId; timers.set(id, fn); return id; },
        clearTimeout(id) { timers.delete(id); },
        api(action, params, method, body) {
            requests.push({ action, body });
            return nextResponse(action, body);
        },
    });
    const funcs = vm.runInContext(source + '\n({ getSettings, saveSettingsToStorage, _flushUserPrefsSettings, _debouncedSyncSettings })', ctx);
    return {
        ctx, funcs, requests,
        respondWith(fn) { nextResponse = fn; },
        fireTimers() { const scheduled = [...timers.values()]; timers.clear(); scheduled.forEach(fn => fn()); },
    };
}

async function testWritesSerializeAndAck() {
    const h = harness();
    const first = deferred(), second = deferred();
    const writes = [];
    h.respondWith((action, body) => {
        assert.equal(action, 'app_settings_save');
        writes.push(body.settings.user_prefs.default_persons);
        return writes.length === 1 ? first.promise : second.promise;
    });
    h.funcs.saveSettingsToStorage({ default_persons: 3, pref_veloce: true });
    h.fireTimers(); // first request is in flight
    await tick();
    assert.deepEqual(writes, [3]);
    h.funcs.saveSettingsToStorage({ default_persons: 4, pref_veloce: false });
    let finished = false;
    const flush = h.funcs._flushUserPrefsSettings().then(() => { finished = true; });
    await tick();
    assert.deepEqual(writes, [3], 'a new write must not overtake a previous write');
    assert.equal(finished, false, 'cannot confirm a save while the old write is in flight');
    first.resolve({ success: true });
    await tick();
    assert.deepEqual(writes, [3, 4], 'the newer value must be written last');
    assert.equal(finished, false);
    second.resolve({ success: true });
    await flush;
    assert.equal(finished, true, 'finish only when the newest SQLite write is acknowledged');
    h.fireTimers();
    await tick();
    assert.deepEqual(writes, [3, 4], 'the old debounce must not issue a trailing write');
}

async function testFailedWriteCanRetry() {
    const h = harness();
    let calls = 0;
    h.respondWith(() => {
        calls++;
        return Promise.resolve(calls === 1 ? { success: false, error: 'database_busy' } : { success: true });
    });
    h.funcs.saveSettingsToStorage({ default_persons: 6 });
    await assert.rejects(h.funcs._flushUserPrefsSettings(), /database_busy/);
    await h.funcs._flushUserPrefsSettings();
    assert.equal(calls, 2, 'failed sync must remain dirty and be retryable');
}

async function testOfflineAcknowledgementIsNotACommit() {
    const h = harness();
    h.respondWith(() => Promise.resolve({ success: true, _offline: true, _queued: true }));
    h.funcs.saveSettingsToStorage({ default_persons: 2 });
    await assert.rejects(h.funcs._flushUserPrefsSettings(), /settings_sync_failed/,
        'an optimistic offline result must not count as a persisted setting');
    h.respondWith(() => Promise.resolve({ success: true }));
    await h.funcs._flushUserPrefsSettings();
    assert.equal(h.requests.length, 2);
}

async function testSupersededQueuedValueIsDropped() {
    const h = harness(), writes = [];
    h.respondWith((action, body) => {
        writes.push(body.settings.user_prefs.default_persons);
        return Promise.resolve({ success: true });
    });
    h.funcs.saveSettingsToStorage({ default_persons: 1 });
    h.fireTimers(); // queued, but not yet dispatched
    h.funcs.saveSettingsToStorage({ default_persons: 5 });
    await h.funcs._flushUserPrefsSettings();
    assert.deepEqual(writes, [5], 'obsolete pending snapshots should be skipped entirely');
}

async function testSavedSnapshotIgnoresLateCacheMutation() {
    const h = harness(), writes = [];
    h.respondWith((action, body) => {
        writes.push(body.settings.user_prefs.default_persons);
        return Promise.resolve({ success: true });
    });
    h.funcs.saveSettingsToStorage({ default_persons: 4, pref_veloce: false }, true);
    // A stale settings-tab refresh modifies the shared cache after the click,
    // while the server is still processing its earlier .env request.
    h.funcs.getSettings().default_persons = 3;
    h.funcs.getSettings().pref_veloce = true;
    await h.funcs._flushUserPrefsSettings();
    assert.deepEqual(writes, [4], 'SQLite must persist the values from the actual user click');
}

async function testPanelResponseRejectedAfterFormEdit() {
    const h = harness(), listeners = {};
    h.ctx.document = { getElementById: id => id === 'page-settings'
        ? { addEventListener: (name, fn) => { listeners[name] = fn; } }
        : null };
    const watch = vm.runInContext('_watchSettingsFormEdits', h.ctx);
    watch();
    assert.equal(typeof listeners.input, 'function');
    assert.equal(typeof listeners.change, 'function');
    const isCurrent = vm.runInContext('_settingsFormLoadIsCurrent', h.ctx);
    assert.equal(isCurrent(0, 0), true);
    listeners.input();
    assert.equal(isCurrent(0, 0), false, 'user input should invalidate an older settings-tab response');
    const nowEdited = vm.runInContext('_settingsFormEditRevision', h.ctx);
    assert.equal(isCurrent(0, nowEdited), true);
    h.funcs.saveSettingsToStorage({ default_persons: 4 }, true);
    assert.equal(isCurrent(0, nowEdited), false, 'saving also invalidates an older settings-tab response');
}

async function testOldInitializationCannotOverwriteEdit() {
    const h = harness(), older = deferred(), applied = [];
    h.ctx._applySyncedSettings = x => applied.push(x.default_persons);
    h.respondWith(action => {
        if (action === 'get_settings') return older.promise;
        if (action === 'app_settings_get') return Promise.resolve({ success: true, settings: {} });
        return Promise.resolve({ success: true });
    });
    const sync = vm.runInContext(syncSource + '\nsyncSettingsFromDB', h.ctx);
    const loading = sync();
    h.funcs.saveSettingsToStorage({ default_persons: 4 });
    older.resolve({ success: true, default_persons: 1 });
    await loading;
    assert.deepEqual(applied, [], 'a stale initial get_settings must not replace a later edit');
    assert.equal(h.funcs.getSettings().default_persons, 4);
}

async function runSaveUi({ db, env, extra } = {}) {
    const status = { textContent: '', className: '', style: {}, dataset: {}, value: '', checked: false };
    const doc = {
        getElementById(id) {
            if (id === 'settings-status') return status;
            return { value: id === 'setting-default-persons' ? '3' : '', checked: false, dataset: {}, style: {} };
        },
        querySelector() { return { dataset: { tab: 'general' } }; },
    };
    const ctx = vm.createContext({
        document: doc,
        getSettings: () => ({ meal_plan: extra ? ['a'] : null }),
        saveSettingsToStorage() {},
        _flushUserPrefsSettings: () => db.promise,
        _getSelectedAiProvider: () => 'gemini',
        _applyTheme() {},
        _checklistPendingBeforeSave: () => [],
        _ackSavedChecklistItems() {},
        _renderSettingsChecklist() {},
        _updateGeminiButtonState() {},
        initInactivityWatcher() {},
        getApiToken: () => '',
        apiAuthHeaders: () => ({}),
        t: s => s,
        iconLabel: (_, s) => s,
        setTimeout() {},
        api(action) {
            if (action === 'save_settings') return Promise.resolve(env ?? { success: true });
            if (action === 'app_settings_save') return Promise.resolve(extra === 'fail'
                ? { success: false, error: 'database_busy' } : { success: true });
            return Promise.resolve({});
        },
        _serverSettings: {},
    });
    const save = vm.runInContext(saveSource + '\nsaveSettings', ctx);
    const done = save();
    await tick();
    return { done, status };
}
async function testUiOnlyConfirmsAfterBothWrites() {
    const db = deferred();
    const r = await runSaveUi({ db });
    assert.notEqual(r.status.className, 'settings-status success',
        'the .env success must not be displayed before SQLite acknowledged');
    db.resolve({ success: true });
    await r.done;
    assert.equal(r.status.className, 'settings-status success');
    const failDb = deferred();
    const f = await runSaveUi({ db: failDb });
    failDb.reject(new Error('database_busy'));
    await f.done;
    assert.equal(f.status.className, 'settings-status error',
        'a failed SQLite sync must not produce a green success');
    const extraDb = deferred();
    extraDb.resolve({ success: true });
    const e = await runSaveUi({ db: extraDb, extra: 'fail' });
    await e.done;
    assert.equal(e.status.className, 'settings-status error',
        'the secondary SQLite settings write must also be acknowledged');
}

(async () => {
    await testWritesSerializeAndAck();
    await testFailedWriteCanRetry();
    await testOfflineAcknowledgementIsNotACommit();
    await testSupersededQueuedValueIsDropped();
    await testSavedSnapshotIgnoresLateCacheMutation();
    await testPanelResponseRejectedAfterFormEdit();
    await testOldInitializationCannotOverwriteEdit();
    await testUiOnlyConfirmsAfterBothWrites();
    console.log('PASS settings sync: serialized writes, retry, stale init and UI acknowledgements');
})().catch(err => { console.error(err); process.exitCode = 1; });
