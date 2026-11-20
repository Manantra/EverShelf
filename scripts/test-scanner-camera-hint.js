#!/usr/bin/env node
'use strict';
// Issue #31: exercise EverShelf's actual camera-denial handler, not a copy.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const dom = fs.readFileSync(path.join(__dirname, '../assets/js/core/dom.js'), 'utf8');
const start = app.indexOf('async function initScanner() {');
const end = app.indexOf('// ===== EAN-13 / EAN-8 CHECKSUM VALIDATOR =====', start);
assert.ok(start >= 0 && end > start, 'real scanner handler must exist');
const realHandler = app.slice(start, end);
const escapeFn = dom.slice(dom.indexOf('function escapeHtml('), dom.indexOf('/**', dom.indexOf('function escapeHtml(')));

function escapeText(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function flatten(o, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(o)) {
    const key = prefix ? prefix + '.' + k : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out);
    else out[key] = v;
  }
  return out;
}
async function denied(locale, injected = null, handler = realHandler) {
  const strings = flatten(JSON.parse(fs.readFileSync(path.join(__dirname, '../translations/' + locale + '.json'))));
  if (injected !== null) strings['scanner.camera_error_hint'] = injected;
  const result = { innerHTML: '', style: {} };
  const viewport = { classList: { add(x) { assert.equal(x, 'scanner-off'); } } };
  let ai = 0, attemptedCamera = 0;
  const document = {
    getElementById(id) {
      if (id === 'scan-result') return result;
      if (id === 'scanner-viewport') return viewport;
      if (id === 'scan-debug-log') return { textContent: '' };
      if (id === 'scanner-video') return {};
      return null;
    },
    createElement() {
      let value = '';
      return { set textContent(v) { value = String(v); },
        get innerHTML() { return escapeText(value); } };
    },
  };
  const ctx = vm.createContext({
    document,
    window: {},
    navigator: { mediaDevices: { async getUserMedia() {
      attemptedCamera++;
      throw Object.assign(new Error('Permission denied'), { name: 'NotAllowedError' });
    } } },
    getCameraConstraints: () => ({ video: true }),
    getSettings: () => ({ camera_facing: 'environment' }),
    scanLog: () => {},
    stopScanner: () => {},
    _resetAiFallbackForNewScan: () => {},
    _clearAiMatchPanel: () => {},
    _scannerAiAllowed: () => { ai++; return true; },
    _useBarcodeDetector: false,
    _geminiAvailable: true,
    escapeAttr: s => escapeText(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;'),
    t: k => strings[k] || k,
    console: { error: () => {} },
  });
  vm.runInContext(escapeFn + '\n' + handler, ctx);
  await vm.runInContext('initScanner()', ctx);
  assert.equal(attemptedCamera, 1, locale + ': camera-denial path must execute');
  assert.equal(result.style.display, 'block', locale + ': error panel visible');
  assert.equal(ai, 1, locale + ': existing AI option remains reachable');
  assert.equal((result.innerHTML.match(/<br>/g) || []).length, 1, locale + ': exactly one real line-break tag');
  assert.equal((result.innerHTML.match(/&lt;br&gt;/g) || []).length, 0, locale + ': no printed br');
  assert.doesNotMatch(result.innerHTML, /<(?:img|script)\b/i, locale + ': no dangerous elements');
  return result.innerHTML;
}
(async () => {
  for (const locale of ['it', 'en', 'de', 'fr', 'es', 'zh']) {
    const html = await denied(locale);
    assert.ok(html.includes('<br>'), locale + ': error hint rendered');
    console.log('PASS camera denial / permitted line break: ' + locale);
  }
  const payload = 'Text <img src=x onerror=alert(1)><script>alert(1)</script><br/>Then & done';
  const html = await denied('en', payload);
  assert.ok(html.includes('&lt;img'), 'unexpected image must stay escaped');
  assert.ok(html.includes('&lt;script'), 'unexpected script must stay escaped');
  assert.ok(html.includes('Then &amp; done'), 'ampersand must stay escaped');
  const oldExpression = "escapeHtml(t('scanner.camera_error_hint'))";
  const newExpression = "t('scanner.camera_error_hint').split(/<br\\s*\\/?>/gi).map(escapeHtml).join('<br>')";
  const broken = realHandler.replace(newExpression, oldExpression);
  assert.notEqual(broken, realHandler, 'mutation guard: expected safe rendering expression');
  await assert.rejects(() => denied('en', null, broken), /exactly one real line-break tag/,
    'old escaped rendering must fail the same real-handler test');
  console.log('PASS injected img/script/event handler remain escaped; br/> supported');
})().catch(e => { console.error(e); process.exitCode = 1; });
