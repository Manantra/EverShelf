#!/usr/bin/env node
'use strict';
// Regression for issue #26, using only a mocked form/timers.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const src = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');

function extract(name) {
  const start = src.indexOf('function ' + name + '(');
  if (start < 0) return '';
  const bodyStart = src.indexOf('{', start);
  assert.ok(bodyStart > start, name + ': missing body');
  let depth = 0;
  for (let i = bodyStart; i < src.length; i++) {
    if (src[i] === '{') depth++;
    if (src[i] === '}' && --depth === 0) return src.slice(start, i + 1);
  }
  throw new Error(name + ': unterminated function');
}

function exercise(maybeFn) {
  let submitCount = 0;
  const timers = [];
  const hint = { style: { display: 'block' }, textContent: '' };
  const form = { requestSubmit: () => { submitCount++; } };
  const page = {
    querySelector: selector => selector === 'form' ? form : null,
    addEventListener() {}, removeEventListener() {},
  };
  const nodes = {
    'use-quantity': { value: '1' },
    'btn-use-submit': { style: { background: 'initial' } },
    'use-idle-hint': hint, 'page-use': page,
  };
  const ctx = vm.createContext({
    document: { getElementById: id => nodes[id] || null },
    setTimeout: (fn, duration) => { timers.push({ fn, duration }); return timers.length; },
    clearTimeout() {},
    requestAnimationFrame: () => 100, cancelAnimationFrame() {},
    performance: { now: () => 0 },
    getComputedStyle: () => ({ backgroundColor: 'rgb(42,42,42)' }),
    t: key => key,
    _currentPageId: 'use',
    _useConfMode: { _activeUnit: 'conf', totalConf: 3 },
    _useNormalUnit: 'conf',
    _useCurrentItems: [{ quantity: 3, location: 'dispensa' }],
  });
  const code = [
    'let _useFormIdleTimer=null,_useFormIdleRAF=null;',
    'let _useFormIdlePageEl=null,_useFormIdleOnInteraction=null;',
    'const USE_FORM_IDLE_MS=15000;',
    extract('_useFormIdleEligible'),
    extract('clearUseFormIdleCountdown'),
    maybeFn,
  ].join('\n');
  vm.runInContext(code, ctx);
  vm.runInContext('_maybeStartUseFormIdleCountdown()', ctx);
  const timerCount = timers.length;
  for (const timer of timers) timer.fn();
  assert.equal(submitCount, 0, '15-second idle MUST NOT submit inventory without user confirmation');
  assert.equal(timerCount, 0, 'Use form MUST NOT arm a delayed auto-submit timer');
  assert.equal(hint.style.display, 'none', 'Old auto-consumption hint must stay hidden');
}

const maybe = extract('_maybeStartUseFormIdleCountdown');
assert.ok(maybe, 'Use form timer hook must remain callable');
assert.ok(extract('clearUseFormIdleCountdown'), 'navigation must still reset Use hint');
const mutated = maybe.replace(
  'clearUseFormIdleCountdown();',
  'clearUseFormIdleCountdown(); setTimeout(() => document.getElementById("page-use").querySelector("form").requestSubmit(), 15000);'
);
assert.notEqual(mutated, maybe, 'mutation could not be injected');
assert.throws(() => exercise(mutated), /MUST NOT (submit inventory|arm a delayed)/,
  'test must fail for an intentionally reintroduced auto-submit');
exercise(maybe);
assert.match(src, /async function submitUse\(e\)\s*\{/,
  'explicit use submission function must remain');
assert.match(src, /api\('inventory_use', \{\}, 'POST'/,
  'manual inventory_use API path must remain');
console.log('PASS Use form cannot auto-submit after inactivity (15s timer fast-forward)');
console.log('PASS negative mutation is rejected; manual use submission remains');
