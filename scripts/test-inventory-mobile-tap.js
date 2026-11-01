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
        quickUse: (...a) => calls.push(['use-form', ...a]),
        quickThrowFromInv: (...a) => calls.push(['disposal-form', ...a]),
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
    assert.deepEqual(calls.splice(0), [['use-form', 42, 'dispensa']],
        'Left swipe opens the consumption form without submitting a write');

    row.dataset.invSwipeDone = '';
    emit('pointerdown', 40, 150);
    emit('pointermove', 140, 150);
    emit('pointerup', 140, 150);
    assert.deepEqual(calls.splice(0), [['disposal-form', 42, 'dispensa', 71]],
        'Right swipe opens reasoned disposal, without submitting a write');

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
    assert(!src.includes('function showInvActionChooser('),
        'The obsolete two-action swipe chooser is no longer reachable');
    const detail = extract('function showItemDetail(inventoryId, productId) {',
        'function itemDetailThrow(');
    assert(detail.includes('item-detail-action--edit') && detail.includes('editInventoryItem('),
        'The detail contains a full-sized Edit action');
    assert(!detail.includes('itemDetailUseAll('),
        'The duplicate full-consumption button was removed from details');
    assert(src.includes('function submitUseAll()'),
        'Safe complete-consumption action remains in the dedicated Use form');
    console.log('PASS tap/left-use/right-disposal/scroll/keyboard and product-detail actions');
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
    // Use summary now displays stock ONCE; the expiry chip may stay in hero.
    const summary = extract('function _renderUseStockSummary(items) {',
        'function _updateUseHeroMeta(items) {');
    assert(summary.includes('use-stock-summary-total'));
    assert(!src.slice(src.indexOf('function _updateUseHeroMeta(items) {'),
        src.indexOf('// Conf-mode tracking for USE form')).includes('use-pill-qty'),
        'Hero must not duplicate the total stock');
    assert(src.includes('infoEl.innerHTML = _renderUseStockSummary(items);'),
        'Use page renders exactly one stock overview for each unit mode');
    assert(!src.includes('showInvActionChooser('));
    const useLoader = extract('async function loadUseInventoryInfo() {', 'function switchUseUnit(mode) {');
    assert(!useLoader.includes('<br><small>${qtyLabel}</small></button>'),
        'Location buttons no longer repeat another copy of the stock');
    const ctx2 = {
        _collationLocale: () => 'de-DE',
        localizedPackageUnitLabel: n => Number(n) === 1 ? 'Packung' : 'Packungen',
        LOCATIONS: { dispensa: { icon: '🗄️', label: 'Vorratskammer' },
            frigo: { icon: '🧊', label: 'Kühlschrank' } },
        t: k => ({'use.available':'Verfügbar','use.weight_total':'insgesamt',
            'use.weight_per_package':'je Packung'})[k] || k,
        stripHtml: String,
        formatQuantity: (n, unit) => unit === 'conf'
            ? n + ' Packungen' : n + ' g',
        getUnitDisplayLabel: String,
        escapeHtml: String,
    };
    vm.createContext(ctx2);
    vm.runInContext(summary + String.fromCharCode(10)
        + 'this.renderSummary = _renderUseStockSummary;',ctx2);
    const one = ctx2.renderSummary([{quantity:14,unit:'conf',default_quantity:690,
        package_unit:'g',location:'dispensa'}]);
    assert.equal((one.match(/14 Packungen/g)||[]).length, 1,
        'Single-location summary has exactly one total quantity');
    assert(one.includes('690 g je Packung · 9.660 g insgesamt'));
    assert(one.includes('Vorratskammer') && !one.includes('14 Packungen</span></span>'),
        'Location badge is a label, not another total');
    const mixed = ctx2.renderSummary([
        {quantity:2,unit:'conf',default_quantity:690,package_unit:'g',location:'dispensa'},
        {quantity:3,unit:'conf',default_quantity:500,package_unit:'g',location:'frigo'},
    ]);
    assert(mixed.includes('2.880 g insgesamt'), 'Mixed pack sizes use weighted sum 2*690+3*500');
    assert(!mixed.includes('690 g je Packung'), 'Mixed package sizes cannot claim identical pack size');
    assert(mixed.includes('Kühlschrank') && mixed.includes('Vorratskammer'),
        'Multiple locations remain visible in the single summary');
    console.log('PASS 14 × 690 g semantics: 690 g per pack vs 9,660 g total');
}
(async () => {
    await gestureSuite();
    testWeightLabels();
    console.log('All iPhone inventory tap, responsive label and package weight regressions passed.');
})().catch(e => { console.error(e); process.exitCode = 1; });
