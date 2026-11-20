#!/usr/bin/env node
'use strict';
// Issue #35: real localized Drive hint content must use safe inline DOM tags.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'assets/js/app.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const keys = {
  'settings.backup.gdrive_folder_id_hint': 'strong',
  'settings.backup.gdrive_redirect_uri_hint': 'strong',
  'settings.backup.gdrive_code_hint': 'code'
};

assert.match(app, /function renderSafeI18nInline\(/, 'safe text-node formatter must exist');
assert.match(app, /querySelectorAll\('\[data-i18n-safe-inline\]'\)/,
  'translatePage must render limited safe inline nodes');

const start = app.indexOf('function renderSafeI18nInline(');
const end = app.indexOf('// Translate all elements with data-i18n attributes', start);
assert.ok(start >= 0 && end > start, 'helper must be standalone');
const helper = app.slice(start, end);

const escape = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const created = [];
class Node {
  constructor(tag, value = '') { this.tag = tag; this.children = []; if (tag === '#text') this.value = String(value); else if (value) this.textContent = value; }
  appendChild(child) { if (child.tag === '#fragment') this.children.push(...child.children); else this.children.push(child); return child; }
  replaceChildren(...nodes) { this.children = []; nodes.forEach(n => this.appendChild(n)); }
  set textContent(t) { this.children = [new Node('#text', t)]; }
  get textContent() { return this.tag === '#text' ? this.value : this.children.map(n => n.textContent).join(''); }
  get innerHTML() { return this.children.map(n => n.tag === '#text' ? escape(n.textContent) :
    '<' + n.tag + '>' + n.innerHTML + '</' + n.tag + '>').join(''); }
}
const ctx = vm.createContext({
  document: {
    createDocumentFragment: () => new Node('#fragment'),
    createTextNode: value => new Node('#text', value),
    createElement: tag => { created.push(tag); return new Node(tag); }
  }
});
vm.runInContext(helper, ctx);
function render(source) {
  created.length = 0;
  const el = new Node('p');
  ctx.renderSafeI18nInline(el, source);
  return el;
}
for (const locale of ['it', 'en', 'de', 'fr', 'es', 'zh']) {
  const strings = JSON.parse(fs.readFileSync(path.join(root, 'translations', locale + '.json')));
  for (const [key, tag] of Object.entries(keys)) {
    const leaf = key.split('.').at(-1);
    const source = strings.settings.backup[leaf];
    const attr = new RegExp('<p\\b[^>]*data-i18n-safe-inline="' + key.replace(/\./g, '\\.') + '"[^>]*>');
    assert.match(html, attr, locale + ': missing safe-inline attribute ' + key);
    const el = render(source);
    assert.deepEqual(created, [tag], locale + ' / ' + key + ': unexpected DOM tags');
    assert.equal((el.innerHTML.match(new RegExp('<' + tag + '>', 'g')) || []).length, 1);
    assert.doesNotMatch(el.innerHTML, /&lt;\/?(?:strong|code)&gt;/i, locale + ': markup printed as text');
    assert.equal(el.textContent, source.replace(/<\/?(?:strong|code)>/g, ''),
      locale + ': must preserve translated text');
  }
  console.log('PASS Drive hints render safe rich nodes in ' + locale);
}
const injected = render('before <img src=x onerror=alert(1)> <strong>safe<script>alert(1)</script></strong> <code onclick="oops">bad</code>');
assert.deepEqual(created, ['strong'], 'only exact allowed tag pair may create an element');
assert.match(injected.innerHTML, /&lt;img/);
assert.match(injected.innerHTML, /&lt;script/);
assert.match(injected.innerHTML, /&lt;code onclick=/);
assert.doesNotMatch(injected.innerHTML, /<(?:img|script)\b/);
assert.equal(injected.textContent.includes('safe<script>'), true, 'nested injected markup must stay literal text');
console.log('PASS unexpected HTML, event handlers, malformed tags remain escaped as text');
