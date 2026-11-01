#!/usr/bin/env node
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const src = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const mobile = fs.readFileSync(path.join(__dirname, '../assets/css/mobile.css'), 'utf8');
function extract(start, end) {
    const a = src.indexOf(start), b = src.indexOf(end, a + start.length);
    assert(a >= 0 && b > a, [start, end]);
    return src.slice(a, b);
}
function node(classes = []) {
    const set = new Set(classes);
    return {
        dataset: { invId: '71', productId: '42', location: 'dispensa', invSwipeDone: '' },
        style: {},
        classList: {
            add: (...names) => names.forEach(x => set.add(x)),
            remove: (...names) => names.forEach(x => set.delete(x)),
            toggle: (x, active) => active ? set.add(x) : set.delete(x),
            contains: x => set.has(x),
        },
        querySelector() { return this.content; },
        setPointerCapture() {},
        releasePointerCapture() {},
        content: null,
    };
}
async function gestureSuite() {
    const calls = [], handlers = new Map();
    const row = node(['inventory-item']), content = node(['inv-row-content']);
    row.content = content;
    const container = {
        addEventListener: (k, fn) => handlers.set(k, fn),
        removeEventListener: (k) => handlers.delete(k),
    };
    const ctx = {
        showItemDetail: (invId, productId) => calls.push(['detail', invId, productId]),
        quickUse: () => calls.push(['use']),
        showInvActionChooser: (...a) => calls.push(['chooser', ...a]),
        editInventoryItem: invId => calls.push(['edit', invId]),
        setTimeout: () => 1,
    };
    vm.createContext(ctx);
    const code = extract('function _initInventoryRowSwipe(container) {',
        'function _playInventorySwipeDemo(container) {');
    vm.runInContext(code + String.fromCharCode(10)
        + 'this.initSwipe = _initInventoryRowSwipe;', ctx);
    ctx.initSwipe(container);
    const target = {
        closest: selector => selector === '.inventory-item' ? row : null,
    };
    const emit = (kind, x, y, pointerType='touch', key=undefined, eventTarget=target) => {
        let prevented = false;
        const event = {
            type: kind, button: 0, pointerId: 7, pointerType, clientX: x, clientY: y,
            target: eventTarget, key,
            preventDefault: () => { prevented = true; },
        };
        handlers.get(kind)(event);
        return prevented;
    };
    emit('pointerdown', 120, 150); emit('pointerup', 120, 150);
    assert.deepEqual(calls.splice(0), [['detail', 71, 42]],
        'Short iPhone tap opens product detail once');

    row.dataset.invSwipeDone = '';
    emit('pointerdown', 120, 150, 'mouse'); emit('pointerup', 120, 150, 'mouse');
    assert.deepEqual(calls.splice(0), [['detail', 71, 42]],
        'Mouse tap also opens product detail instead of going to Use');

    row.dataset.invSwipeDone = '';
    emit('pointerdown', 120, 150);
    emit('pointermove', 40, 151);
    emit('pointerup', 40, 151);
    assert.deepEqual(calls.splice(0), [['chooser', 42, 'dispensa', 71]],
        'Left swipe still opens Use/Dispose chooser');

    row.dataset.invSwipeDone = '';
    emit('pointerdown', 40, 150);
    emit('pointermove', 140, 150);
    emit('pointerup', 140, 150);
    assert.deepEqual(calls.splice(0), [['edit', 71]],
        'Right swipe still edits');

    row.dataset.invSwipeDone = '';
    emit('pointerdown', 120, 150);
    emit('pointermove', 120, 190);
    emit('pointerup', 120, 190);
    assert.equal(calls.length, 0, 'Vertical scrolling never opens details');

    row.dataset.invSwipeDone = '';
    emit('pointerdown', 120, 150);
    emit('pointercancel', 120, 150);
    assert.equal(calls.length, 0, 'iOS pointercancel never opens details');

    row.dataset.invSwipeDone = '';
    const favorite = { closest: q => q.startsWith('.btn-inv-fav') ? {} : row };
    emit('pointerdown', 120, 150, 'touch', undefined, favorite);
    emit('pointerup', 120, 150, 'touch', undefined, favorite);
    assert.equal(calls.length, 0, 'Favorite button does not open detail underneath');

    row.closest = selector => selector === '.inventory-item' ? row : null;
    row.dataset.invSwipeDone = '';
    assert(emit('keydown', 0, 0, 'mouse', 'Enter', row));
    assert.deepEqual(calls.splice(0), [['detail', 71, 42]],
        'Keyboard Enter also opens product details');
    assert(src.includes('role="button" tabindex="0" aria-label='),
        'Row is keyboard-focusable and has a translated accessible name');
    assert(mobile.includes('grid-template-columns: 44px minmax(0, 1fr) max-content;'),
        'Phone inventory row uses a layout that reserves space for location name');
    assert(mobile.includes('#inventory-list .badge-location {')
        && mobile.includes('white-space: nowrap;'),
        'Phone pantry label will not break Vorratskammer mid-word');
    assert(src.includes("t('units.conf_short') + ' (' + t('units.conf') + ')'"),
        'Edit unit select uses localized Pkg (Packung), not Packung (Packungen)');
    console.log('PASS real inventory tap/swipe/scroll/keyboard and mobile location layout');
}
function testWeightLabels() {
    const code = extract('function _formatUsePackageStock(count, perPackage, subUnit, showTotal = false) {',
        'function _updateUseHeroMeta(items) {');
    const ctx = {
        _collationLocale: () => 'de-DE',
        localizedPackageUnitLabel: n => Number(n) === 1 ? 'Packung' : 'Packungen',
        t: k => ({ 'use.weight_total': 'insgesamt',
            'use.weight_per_package': 'je Packung' })[k] || k,
    };
    vm.createContext(ctx);
    vm.runInContext(code + String.fromCharCode(10)
        + 'this.formatPack = _formatUsePackageStock;', ctx);
    assert.equal(ctx.formatPack(14, 690, 'g'),
        '14 Packungen (690 g je Packung)');
    assert.equal(ctx.formatPack(14, 690, 'g', true),
        '14 Packungen (insgesamt: 9.660 g)');
    assert.equal(ctx.formatPack(1, 690, 'g', true),
        '1 Packung (insgesamt: 690 g)');
    assert.equal(ctx.formatPack(14, 0, 'g'), '', 'Unknown package size is not invented');
    assert(src.includes('_formatUsePackageStock(totalQty, items[0]?.default_quantity,'),
        'Use-page product hero uses per-package label');
    assert(src.includes('parseFloat(i.default_quantity) || pkgSize, i.package_unit || subLabel, true'),
        'Available count uses per-row package size and computed total');
    console.log('PASS 14 × 690 g semantics: 690 g per pack vs 9,660 g total');
}
(async () => {
    await gestureSuite();
    testWeightLabels();
    console.log('All iPhone inventory tap, responsive label and package weight regressions passed.');
})().catch(e => { console.error(e); process.exitCode = 1; });
