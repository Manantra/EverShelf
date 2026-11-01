#!/usr/bin/env node
'use strict';

/** Real unit-badge renderer with a tiny DOM double (not a screenshot). */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const js = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../assets/css/style.css'), 'utf8');
const mobile = fs.readFileSync(path.join(__dirname, '../assets/css/mobile.css'), 'utf8');
function slice(start, end) {
    const a = js.indexOf(start), b = js.indexOf(end, a + start.length);
    assert(a >= 0 && b > a, [start, end].join(' -> '));
    return js.slice(a, b);
}
const src = slice('function getUnitDisplayLabel(unit) {', '/** Wrap qty input')
    + String.fromCharCode(10) + slice('function setQtyInputUnitLabel(inputId, unit, muted = false) {',
        'function syncAddQtyUnitBadge() {');

const translations = {
    de: JSON.parse(fs.readFileSync(path.join(__dirname, '../translations/de.json'), 'utf8')),
    en: JSON.parse(fs.readFileSync(path.join(__dirname, '../translations/en.json'), 'utf8')),
};
function unitBadge(language) {
    const badge = {
        children: [],
        attrs: {},
        textContent: '',
        classList: { toggles: [], toggle(name, state) { this.toggles.push([name, state]); } },
        replaceChildren() { this.children = []; this.textContent = ''; },
        append(...els) { this.children.push(...els); },
        setAttribute(name, value) { this.attrs[name] = value; },
        title: '',
    };
    const scope = {
        ensureQtyUnitBadge: () => badge,
        document: {
            getElementById: () => badge,
            createElement: name => ({
                tagName: name,
                attrs: {},
                setAttribute(key, val) { this.attrs[key] = val; },
            }),
        },
        t: key => {
            const [group, entry] = key.split('.');
            return translations[language][group]?.[entry] ?? key;
        },
    };
    vm.createContext(scope);
    vm.runInContext(src + String.fromCharCode(10) + 'this.paint = setQtyInputUnitLabel;', scope);
    return { paint: scope.paint, badge };
}

for (const lang of ['de', 'en']) {
    const { paint, badge } = unitBadge(lang);
    paint('use-quantity', 'conf');
    assert.equal(badge.children.length, 2, lang + ': long and short labels');
    assert.equal(badge.children[0].textContent, translations[lang].units.conf);
    assert.equal(badge.children[1].textContent, translations[lang].units.conf_short);
    assert.equal(badge.children[1].attrs['aria-hidden'], 'true');
    assert.equal(badge.attrs['aria-label'], translations[lang].units.conf);
    assert(badge.title.includes(translations[lang].units.conf));
    paint('use-quantity', 'g');
    assert.equal(badge.children.length, 0, 'g must not inherit an old package badge');
    assert.equal(badge.textContent, 'g');
    paint('use-quantity', 'conf');
    assert.equal(badge.children.length, 2, 'unit switching back to conf works');
}

assert(css.includes('.qty-unit-compact { display: none; }'));
assert(mobile.includes('@media (max-width: 440px)'));
assert(mobile.includes('.qty-unit-badge .qty-unit-compact { display: inline; }'));
assert(mobile.includes('.qty-unit-badge .qty-unit-full { display: none; }'));
assert(js.includes("setQtyInputUnitLabel('throw-quantity', items[0]?.unit || 'pz');"),
    'Disposal form uses identical responsive unit badges');
assert(js.includes('data.items_consumed === 0 && data.items_wasted === 0 && data.items_added === 0'),
    'Waste-only monthly activity remains visible');
console.log('PASS responsive compact package labels, accessibility, mode switches and waste-only statistics');
