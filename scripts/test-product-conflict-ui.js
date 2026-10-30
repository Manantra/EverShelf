#!/usr/bin/env node
'use strict';

/** Test the real manual product edit handlers with a simulated 409 response. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');

function extract(start, end) {
    const a = source.indexOf(start);
    const b = source.indexOf(end, a + start.length);
    assert(a >= 0 && b > a, 'Missing UI handler: ' + start);
    return source.slice(a, b);
}

async function inventoryEditorConflict() {
    const calls = [];
    const toasts = [];
    const openedName = 'Passierte Tomaten';
    const fields = {
        'edit-product-name': { value: 'Passierte TOMATEN', style: { display: 'none' } },
        'edit-title-display': { textContent: openedName },
        'edit-qty': { value: '1' },
        'edit-loc': { value: 'dispensa' },
        'edit-expiry': { value: '' },
        'edit-unit': { value: 'conf' },
        'edit-conf-size': { value: '690' },
        'edit-conf-unit': { value: 'g' },
        'edit-vacuum': { checked: false },
    };
    const context = {
        window: { _editingProduct: { _originalName: openedName } },
        _findInventoryItem: () => ({
            name: openedName, brand: '', category: 'conserve', unit: 'conf',
            package_unit: 'g', default_quantity: 690,
        }),
        document: { getElementById: id => fields[id] || null },
        _expiryUserSetPayload: () => 0,
        t: key => 'TRANSLATED:' + key,
        showToast: (message, severity) => toasts.push({ message, severity }),
        api: async (action, args, method, payload) => {
            calls.push({ action, payload });
            return { success: false, error: 'product_conflict' };
        },
    };
    const submit = vm.runInNewContext(extract(
        'async function submitEditInventory(e, id, productId) {',
        '// ===== SCAN DEBUG LOG ====='
    ) + '\nsubmitEditInventory;', context);
    await submit({ preventDefault() {} }, 51, 14);
    assert.deepEqual(calls.map(x => x.action), ['product_save'],
        'Rejected stale title MUST NOT proceed to inventory_update');
    assert.equal(calls[0].payload.expected_name, openedName);
    assert.equal(calls[0].payload.name_user_set, 1);
    assert.deepEqual(toasts, [{ message: 'TRANSLATED:error.product_conflict', severity: 'error' }]);
}

async function productActionConflict() {
    const calls = [], toasts = [];
    const originalName = 'Passierte Tomaten';
    const elements = {
        'edit-action-name': { value: 'Passierte TOMATEN' },
        'edit-action-brand': { value: '' },
        'edit-action-category': { value: 'conserve' },
        'edit-action-notes': { value: 'My private note' },
        'action-edit-info': { dataset: { originalName } },
    };
    const context = {
        document: { getElementById: id => elements[id] || null },
        currentProduct: {
            id: 14, name: originalName, unit: 'conf', category: 'conserve',
            default_quantity: 690, barcode: null,
        },
        showLoading: () => {},
        showToast: (message, severity) => toasts.push({ message, severity }),
        t: key => 'TRANSLATED:' + key,
        api: async (action, args, method, payload) => {
            calls.push({ action, payload });
            return { success: false, error: 'product_conflict' };
        },
    };
    const save = vm.runInNewContext(extract(
        'async function saveEditedProductInfo() {',
        '// ===== SCAN / ADD:'
    ) + '\nsaveEditedProductInfo;', context);
    await save();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].payload.expected_name, originalName);
    assert.deepEqual(toasts, [{ message: 'TRANSLATED:error.product_conflict', severity: 'error' }]);
    assert.equal(context.currentProduct.name, originalName,
        'Rejected edit does not update local product cache');
}

(async () => {
    await inventoryEditorConflict();
    await productActionConflict();
    console.log('Both manual product edit screens preserve data on stale conflicts.');
})().catch(error => { console.error(error); process.exitCode = 1; });
