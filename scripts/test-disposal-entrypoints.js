#!/usr/bin/env node
'use strict';
/**
 * Real client entry-point functions executed in a tiny DOM/HTTP test harness.
 * No changes to production or staging data. Distinguishes disposal
 * (reasoned inventory_use) from an explicit inventory-only deletion.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const js = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');

function code(start, end) {
    const pos = js.indexOf(start);
    assert(pos >= 0, start);
    const finish = js.indexOf(end, pos + start.length);
    assert(finish > pos, end);
    return js.slice(pos, finish);
}

async function testDetailAndSwipeRouteToOneWasteForm() {
    const source = code('function itemDetailThrow(inventoryId) {', 'async function itemDetailUseAll(')
        + '\n' + code('async function quickThrowFromInv(productId, location, invId) {', 'const WASTE_REASON_KEYS');
    const item = {
        id: 71, product_id: 42, name: 'Demo', image_url: 'https://example.invalid/item.png',
        location: 'frigo', quantity: 3, unit: 'conf',
        default_quantity: 690, package_unit: 'g',
    };
    const calls = [];
    const ctx = {
        currentProduct: null,
        _findInventoryItem: id => id === item.id ? item : null,
        closeModal: () => calls.push('close'),
        showThrowForm: loc => calls.push(['waste-form', loc]),
        showToast: () => assert.fail('A valid inventory row should not generate an error'),
        t: key => key,
        api: async action => {
            calls.push(['api', action]);
            assert.equal(action, 'product_get');
            return { product: item };
        },
    };
    vm.createContext(ctx);
    vm.runInContext(source + '\nthis.detail = itemDetailThrow;this.swipe = quickThrowFromInv;', ctx);
    await ctx.detail(item.id);
    assert.equal(calls[0], 'close', 'Product detail closes before navigating to disposal');
    assert.deepEqual(calls.at(-1), ['waste-form', 'frigo']);
    assert.equal(ctx.currentProduct.id, item.product_id);
    calls.length = 0;
    await ctx.swipe(item.product_id, item.location, item.id);
    assert.deepEqual(calls.at(-1), ['waste-form', 'frigo'],
        'Swipe and detail take the same waste path');
    assert(calls.every(c => !Array.isArray(c) || c[1] !== 'inventory_delete'));
    console.log('PASS detail 🗑 and swipe both open the same reasoned waste flow');
}

async function testPreferredLocationIsPreserved() {
    const source = code('function showThrowForm(preferredLocation = null) {',
        'function selectThrowLocation(');
    const rows = [
        { id: 11, product_id: 42, name: 'Demo', location: 'dispensa',
            quantity: 2, unit: 'conf', default_quantity: 690, package_unit: 'g' },
        { id: 71, product_id: 42, name: 'Demo', location: 'frigo',
            quantity: 3, unit: 'conf', default_quantity: 690, package_unit: 'g' },
    ];
    const modal = { innerHTML: '' };
    const context = {
        currentProduct: { id: 42, name: 'Demo', category: 'conserve' },
        LOCATIONS: { dispensa: { icon: '📦', label: 'Vorrat' },
            frigo: { icon: '🧊', label: 'Kühlschrank' } },
        CATEGORY_ICONS: { conserve: '📦' },
        mapToLocalCategory: () => 'conserve',
        formatQuantity: (qty, unit) => String(qty) + ' ' + unit,
        getUnitDisplayLabel: () => 'Packung',
        setQtyInputUnitLabel: (id, unit) => {
            assert.equal(id, 'throw-quantity');
            assert.equal(unit, 'conf');
        },
        escapeHtml: String,
        t: k => k,
        document: { getElementById: id => {
            if (id === 'modal-content') return modal;
            if (id === 'modal-overlay') return { style: { display: 'none' } };
            return null;
        } },
        api: async action => {
            assert.equal(action, 'inventory_list');
            return { inventory: rows };
        },
        showToast: () => assert.fail('Disposal form should show both inventory locations'),
    };
    vm.createContext(context);
    vm.runInContext(source + '\nthis.openWaste = showThrowForm;', context);
    context.openWaste('frigo');
    await new Promise(resolve => setImmediate(resolve));
    assert(modal.innerHTML.includes('id="throw-location" value="frigo"'),
        'The inventory row location, not the first location, must be selected');
    assert(modal.innerHTML.includes('class="loc-btn active" onclick="selectThrowLocation(this, \'frigo\')"'));
    assert(modal.innerHTML.includes('class="loc-btn " onclick="selectThrowLocation(this, \'dispensa\')"'));
    console.log('PASS disposal from selected inventory location preserves that location');
}

async function testWasteWritesReasonButRemovalNeverDoes() {
    const source = code('function _wasteNotesForReason(reason) {',
        'function _showWasteReasonModal(')
        + '\n' + code('function _inventoryWaste(payload, productLabel) {',
        '/** Pure inventory removal')
        + '\n' + code('async function removeInventoryOnly(id) {', '/** Track manual expiry edits')
        + '\n' + code('async function deleteActionInventoryItem(id) {', '// === THROW AWAY FORM ===');
    const sent = [];
    let allow = false, current = null, refreshes = 0, closes = 0;
    const ctx = {
        _findInventoryItem: id => id === 71 ? { id: 71, name: 'Demo' } : null,
        _showWasteReasonModal: (label, pick) => {
            assert.equal(label, 'Demo');
            pick('expired');
        },
        api: async (action, params, method, payload) => {
            sent.push({ action, method, payload });
            return { success: true };
        },
        showLoading: () => {},
        confirm: () => allow,
        t: k => k,
        closeModal: () => { closes++; },
        showToast: () => {},
        refreshAppDataAfterMutation: () => { refreshes++; },
        showProductAction: () => { current = 'refreshed'; },
    };
    vm.createContext(ctx);
    vm.runInContext(source + '\nthis.dispose = _inventoryWaste;this.remove = removeInventoryOnly;this.deleteFromEditor = deleteActionInventoryItem;',ctx);
    const res = await ctx.dispose({ product_id: 42, quantity: 1, location: 'frigo' }, 'Demo');
    assert.equal(res.success, true);
    assert.equal(sent.length, 1);
    assert.equal(sent[0].action, 'inventory_use');
    assert.equal(sent[0].payload.notes, 'Buttato|expired');
    assert.equal(sent[0].payload.location, 'frigo');
    assert.equal(sent[0].payload.quantity, 1);
    assert.equal(await ctx.remove(71), false, 'Cancel inventory removal has no server write');
    assert.equal(sent.length, 1);
    allow = true;
    assert.equal(await ctx.remove(71), true);
    assert.equal(sent[1].action, 'inventory_delete');
    assert.equal(JSON.stringify(sent[1].payload), JSON.stringify({ id: 71 }));
    assert.equal(refreshes, 1);
    assert.equal(await ctx.deleteFromEditor(71), undefined);
    assert.equal(sent[2].action, 'inventory_delete');
    assert.equal(current, 'refreshed');
    assert.equal(sent.filter(x => x.action === 'inventory_use').length, 1,
        'Neither inventory-only removal entry point sends consumption or waste');
    console.log('PASS disposal posts inventory_use with reason; explicit removal posts inventory_delete only');
}

function testConsistentGermanDisposalCopy() {
    const dict = JSON.parse(fs.readFileSync(
        path.join(__dirname, '../translations/de.json'), 'utf8'));
    const labels = [
        dict.inventory.item_detail_throw,
        dict.inventory.swipe_chooser_throw.replace(/^🗑️\s*/, ''),
        dict.dashboard.stale_throw,
        dict.status.discard,
    ];
    assert.deepEqual(labels, Array(4).fill('Entsorgen'),
        'Tap, swipe, long-unused quick action and status must use the same German verb');
    assert.equal(dict.use.throw_qty_label, 'Wie viel entsorgen?');
    assert.equal(dict.action.throw_sub, 'entsorgen');
    assert.equal(dict.dashboard.trend_wasted, 'Entsorgte Produkte');
    assert.equal(dict.units.conf_short, 'Pkg', 'German package abbreviation has no trailing dot');
    assert.equal(dict.units.pz, 'Stk', 'Keep the existing unit abbreviation convention');
    assert(dict.inventory.swipe_hint.includes('Tippen = Details'),
        'The swipe guide must not falsely promise consumption on tap');
    assert(dict.inventory.swipe_guide.includes('Entsorgen'));
    assert(!dict.inventory.swipe_guide.includes('Wegwerfen'));
    assert(js.includes('return quickThrowFromInv(item.product_id, item.location, inventoryId);'));
    console.log('PASS same Entsorgen label across access paths; Pkg without dot');
}

(async () => {
    testConsistentGermanDisposalCopy();
    await testDetailAndSwipeRouteToOneWasteForm();
    await testPreferredLocationIsPreserved();
    await testWasteWritesReasonButRemovalNeverDoes();
    console.log('All product-detail disposal entry point regression tests passed.');
})().catch(err => { console.error(err); process.exitCode = 1; });
