#!/usr/bin/env node
'use strict';

/** Real heartbeat functions in a deterministic fake browser/network harness.
 * Run: node scripts/test-heartbeat.js
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const src = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const start = src.indexOf('// ===== SERVER HEARTBEAT =====');
const end = src.indexOf('/** Flush log messages and error reports', start);
assert(start >= 0 && end > start);
const retryStart = src.indexOf('function _heartbeatRetry() {', end);
const retryEnd = src.indexOf('// ── Startup / Splash health check', retryStart);
assert(retryStart > end && retryEnd > retryStart);
const heartbeat = src.slice(start, end) + '\n' + src.slice(retryStart, retryEnd);


function harness(fetchHandler, { offlineMode = false } = {}) {
    const timers = new Map();
    const calls = { overlays: 0, restored: 0, toggles: [], fetches: 0 };
    let nextId = 0;
    const scope = {
        API_BASE: '/api/index.php',
        _offlineMode: offlineMode,
        AbortController,
        fetch: async (...args) => {
            calls.fetches++;
            return fetchHandler(...args);
        },
        document: {
            body: { classList: {
                toggle: (key, value) => calls.toggles.push([key, value]),
            } },
        },
        _showNetworkOverlay: () => { calls.overlays++; },
        _handleServerRestored: () => {
            calls.restored++;
            scope._offlineMode = false; // actual restore handler exits cache mode
        },
        setTimeout: (fn, ms) => {
            const id = ++nextId;
            timers.set(id, { fn, ms });
            return id;
        },
        clearTimeout: (id) => timers.delete(id),
    };
    vm.createContext(scope);
    vm.runInContext(heartbeat + '\nthis.heartbeatTest = { run: _runHeartbeat, retry: _heartbeatRetry, offline: () => _serverOffline, failures: () => _heartbeatFailures };', scope);
    return {
        ...scope.heartbeatTest, calls,
        timers,
        fire: (ms) => {
            const found = [...timers].find(([, t]) => t.ms === ms);
            assert(found, 'Expected timer ' + ms + ' ms; got ' + JSON.stringify([...timers.values()].map(t => t.ms)));
            timers.delete(found[0]);
            found[1].fn();
        },
        delays: () => [...timers.values()].map(t => t.ms),
    };
}

async function testSlowSingleFailure() {
    const answers = [{ ok: false }, { ok: true }];
    const h = harness(async () => answers.shift());
    await h.run();
    assert.equal(h.offline(), false, 'One rejected ping must not block the UI');
    assert.equal(h.calls.overlays, 0, 'One failed ping must not open full-screen overlay');
    assert.deepEqual(h.delays(), [5000], 'Confirmation should retry at the existing offline interval');
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), false);
    assert.equal(h.calls.overlays, 0);
    assert.equal(h.calls.restored, 0, 'Never fire a restoration when server was never marked offline');
    assert.deepEqual(h.delays(), [20000], 'Healthy server restores 20-second polling');
}

async function testSustainedOutageRecovery() {
    const answers = [{ ok: false }, { ok: false }, { ok: false }, { ok: true }, { ok: true }];
    const h = harness(async () => answers.shift());
    await h.run();
    assert.equal(h.offline(), false);
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), true, 'Two consecutive failures must mark server offline');
    assert.equal(h.calls.overlays, 1, 'Only one overlay should appear');
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.calls.overlays, 1, 'Offline retries must not reopen overlays');
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), false, 'A successful ping recovers immediately');
    assert.equal(h.calls.restored, 1, 'Restore only on transition');
    assert.deepEqual(h.delays(), [20000]);
    h.fire(20000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.calls.restored, 1, 'Healthy followup must not emit duplicate restore');
}

async function testTimeout() {
    const h = harness((url, opts) => new Promise((resolve, reject) => {
        opts.signal.addEventListener('abort', () => reject(new Error('aborted')));
    }));
    const probe = h.run();
    assert.deepEqual(h.delays(), [15000], 'Ping timeout must tolerate a slow reverse proxy');
    h.fire(15000);
    await probe;
    assert.equal(h.offline(), false, 'One timeout alone must not block the UI');
    assert.deepEqual(h.delays(), [5000]);
}

async function testSlowButSuccessfulPing() {
    let resolveFetch;
    const h = harness((url, opts) => new Promise(resolve => { resolveFetch = resolve; }));
    const pending = h.run();
    assert.deepEqual(h.delays(), [15000], 'Slow response gets a 15-second window');
    assert.equal(h.calls.overlays, 0);
    // A slow response that completes before the timeout is still success.
    resolveFetch({ ok: true });
    await pending;
    assert.equal(h.calls.overlays, 0);
    assert.equal(h.offline(), false);
    assert.deepEqual(h.delays(), [20000]);
}

async function testConsecutiveHttpErrors() {
    const answers = [{ ok: false }, { ok: false }, { ok: true }];
    const h = harness(async () => answers.shift());
    await h.run();
    assert.equal(h.offline(), false, 'One HTTP failure is not yet offline');
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), true, 'Persistent HTTP errors are an outage');
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), false, 'HTTP recovery restores normal state');
}

async function testIntentionalOfflineCacheMode() {
    const h = harness(async () => ({ ok: false }), { offlineMode: true });
    await h.run();
    h.fire(5000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), true);
    assert.equal(h.calls.overlays, 0, 'Never cover a deliberately activated offline cache UI');
}

async function testSafariNativeOfflineWhileHeartbeatWasOnline() {
    // On iOS, a browser 'offline' event may show the cache before the
    // heartbeat detects an outage. The heartbeat remains online->online, but
    // a healthy ping must still exit the cached offline UI.
    const h = harness(async () => ({ ok: true }), { offlineMode: true });
    await h.run();
    assert.equal(h.offline(), false, 'Server heartbeat was never offline');
    assert.equal(h.calls.restored, 1,
        'Healthy ping must exit Safari cached offline mode even if server status never flipped');
    h.fire(20000);
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.calls.restored, 1, 'Recovery does not loop once cache mode exited');
}

async function testSafariVisibilityAndPageshowRecovery() {
    // Safari background suspension can suppress timers and the 'online'
    // event. Verify the installed real event listeners proactively ping.
    const start = src.indexOf('// Browser-native online/offline events');
    const end = src.indexOf('// ===== SCREENSAVER', start);
    assert(start >= 0 && end > start);
    const handlers = {};
    let pings = 0;
    const doc = {
        hidden: true,
        addEventListener: (event, fn) => { handlers['document:' + event] = fn; },
    };
    const ctx = {
        window: { addEventListener: (event, fn) => { handlers['window:' + event] = fn; } },
        document: doc,
        _offlineMode: true,
        _serverOffline: false,
        _offlineBannerTimer: null,
        clearTimeout: () => {},
        _showNetworkOverlay: () => {},
        _networkPingOnce: () => {},
        _heartbeatRetry: () => { pings++; },
    };
    vm.runInNewContext(src.slice(start, end), ctx);
    handlers['document:visibilitychange']();
    assert.equal(pings, 0, 'A hidden page must not start network requests');
    doc.hidden = false;
    handlers['document:visibilitychange']();
    handlers['window:pageshow']();
    assert.equal(pings, 2, 'A newly visible offline PWA resumes server connectivity probes');
    ctx._offlineMode = false;
    handlers['document:visibilitychange']();
    handlers['window:pageshow']();
    assert.equal(pings, 2, 'Healthy online foreground app avoids redundant resume probes');
}

async function testInFlightRetry() {
    let release;
    const h = harness(() => new Promise(resolve => { release = resolve; }));
    const first = h.run();
    h.retry();
    assert.equal(h.calls.fetches, 1, 'Retry during an in-flight ping must not spawn a competing request');
    release({ ok: true });
    await first;
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(h.offline(), false);
    assert.equal(h.calls.overlays, 0);
    assert(h.delays().length === 1, 'Only one next heartbeat timer should be active');
}

(async () => {
    await testSlowSingleFailure();
    await testSustainedOutageRecovery();
    await testTimeout();
    await testSlowButSuccessfulPing();
    await testConsecutiveHttpErrors();
    await testIntentionalOfflineCacheMode();
    await testSafariNativeOfflineWhileHeartbeatWasOnline();
    await testSafariVisibilityAndPageshowRecovery();
    await testInFlightRetry();
    console.log('All heartbeat state/timeout/retry regression tests passed.');
})().catch(err => {
    console.error(err);
    process.exitCode = 1;
});
