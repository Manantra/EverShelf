#!/usr/bin/env node
'use strict';
/**
 * Regression for iPhone swipe showing false "Produkt nicht im Bestand" on
 * HTTP 429. Executes actual inventory and disposal/read routines in a
 * virtualized tiny DOM; never writes inventory.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const js = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
const css = fs.readFileSync(path.join(__dirname, '../assets/css/style.css'), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
function extract(start, end) {
    const first = js.indexOf(start);
    const last = js.indexOf(end, first + start.length);
    assert(first >= 0 && last > first, [start, end]);
    return js.slice(first, last);
}
async function testDisposal429AndAuthoritativeAbsence() {
    const mod = { innerHTML: 'UNCHANGED' };
    const overlay = { style: { display: 'none' } };
    const seen = [];
    let next = { success: false, error: 'Too many requests' };
    const ctx = {
        currentProduct: { id: 42, name: 'Demo', category: 'conserve', image_url: null },
        api: async action => { assert.equal(action, 'inventory_list'); return next; },
        showToast: (...args) => seen.push(args),
        document: { getElementById: id =>
            id === 'modal-content' ? mod : id === 'modal-overlay' ? overlay : null },
        LOCATIONS: { dispensa: { icon: '📦', label: 'Vorratskammer' } },
        CATEGORY_ICONS: { conserve: '📦' }, mapToLocalCategory: () => 'conserve',
        escapeHtml: String, t: k => k,
        formatQuantity: q => String(q) + ' Pkg',
        getUnitDisplayLabel: () => 'Packung',
        setQtyInputUnitLabel: () => {},
        console: { warn: () => {} },
    };
    vm.createContext(ctx);
    vm.runInContext(extract('let _throwFormRequestId = 0;',
        'function selectThrowLocation(') + String.fromCharCode(10)
        + 'this.openDispose = showThrowForm;', ctx);
    ctx.openDispose('dispensa');
    await tick();
    assert.deepEqual(seen, [['Too many requests', 'error']],
        'A 429 error must remain a rate-limit error, NOT a false no-stock toast');
    assert.equal(mod.innerHTML, 'UNCHANGED');
    assert.equal(overlay.style.display, 'none', 'A failed read cannot open a stale form');

    next = { success: true, inventory: [{id: 71, product_id: 42,
        name: 'Demo', location:'dispensa', quantity: 14,
        unit:'conf', default_quantity: 690, package_unit:'g'}] };
    seen.length = 0;
    ctx.openDispose('dispensa');
    await tick();
    assert.equal(seen.length, 0);
    assert(mod.innerHTML.includes('id="throw-location" value="dispensa"'));
    assert.equal(overlay.style.display, 'flex');
    console.log('PASS 429 does not claim missing stock; subsequent successful swipe opens disposal');

    mod.innerHTML = 'STILL INTACT'; overlay.style.display = 'none';
    next = { success:true,inventory:[] };
    ctx.openDispose('dispensa');
    await tick();
    assert.deepEqual(seen, [['error.not_in_inventory','error']],
        'Only an authoritative successful empty inventory can claim no stock');
    assert.equal(mod.innerHTML,'STILL INTACT');
    seen.length=0;
    next = Promise.reject(new Error('network offline'));
    ctx.openDispose('dispensa');
    await tick();
    assert.deepEqual(seen, [['error.connection','error']]);
    console.log('PASS real empty success vs rejected network response are treated distinctly');
}
async function testDisposalResponseBelongsToProduct() {
    let resolve;
    const deferred = new Promise(r => { resolve = r; });
    const toasts = [];
    const ctx = {
        currentProduct: {id: 42, name:'Old Demo'},
        api: () => deferred, showToast: (...args) => toasts.push(args),
        document: { getElementById: () => assert.fail('Stale response must not render a modal') },
    };
    vm.createContext(ctx);
    vm.runInContext(extract('let _throwFormRequestId = 0;',
        'function selectThrowLocation(') + String.fromCharCode(10)
        + 'this.openDispose = showThrowForm;', ctx);
    ctx.openDispose('dispensa');
    ctx.currentProduct={id:43,name:'Other'};
    resolve({success:true,inventory:[{id:71,product_id:42,location:'dispensa'}]});
    await tick();
    assert.equal(toasts.length,0,'Late request for old product must not falsely warn');
    console.log('PASS stale product response cannot replace another product dialog');
}
async function testLatestSwipeWinsForSameProduct() {
    const requests = [], changes = [];
    const ctx = {
        currentProduct: {id:42,name:'Demo'},
        api: () => new Promise(resolve => requests.push(resolve)),
        document: { getElementById: id =>
            id === 'modal-content' ? { set innerHTML(html) {
                changes.push(html);
            } } : {style:{display:'none'}} },
        showToast: () => assert.fail('Stale first swipe must not report an error'),
        LOCATIONS: {
            dispensa: {icon:'📦',label:'Vorratskammer'},
            frigo: {icon:'🧊',label:'Kühlschrank'},
        },
        CATEGORY_ICONS:{}, mapToLocalCategory:()=>'',escapeHtml:String,
        t:k=>k, formatQuantity: q=>String(q), getUnitDisplayLabel:()=> 'Packung',
        setQtyInputUnitLabel:()=>{},
    };
    vm.createContext(ctx);
    vm.runInContext(extract('let _throwFormRequestId = 0;',
        'function selectThrowLocation(') + String.fromCharCode(10)
        + 'this.openDispose=showThrowForm;',ctx);
    const inventory = {success:true,inventory:[
        {id:11, product_id:42,location:'dispensa',quantity:2,unit:'conf'},
        {id:71, product_id:42,location:'frigo',quantity:3,unit:'conf'},
    ]};
    ctx.openDispose('dispensa'); ctx.openDispose('frigo');
    assert.equal(requests.length,2);
    requests[1](inventory);
    await tick();
    const count=changes.length;
    assert.equal(count,1);
    assert(changes[0].includes('id="throw-location" value="frigo"'));
    requests[0](inventory);
    await tick();
    assert.equal(changes.length,count,
        'Late first swipe must not replace the newer location dialog');
    console.log('PASS most recent swipe wins even when the same product has two locations');
}

async function testPantryRefreshUsesOneRequestAndSurvives429() {
    const old=[{id:71,product_id:42,location:'dispensa'}];
    const full=[...old,{id:72,product_id:43,location:'frigo'}];
    const calls=[],renders=[];
    let response={success:false,error:'Too many requests'};
    const ctx={
        currentInventory:old,
        _allInventoryCache:old,
        currentLocation:'frigo',
        _inventorySearchQuery:'',
        api: async (a,opts) => {calls.push([a,opts]);return response;},
        _restoreInventorySearchInput:()=>{},
        _inventoryVisibleItems:x=>x,
        document:{getElementById:()=>({value:''})},
        renderInventory:x=>renders.push(x),
        filterInventory:()=>assert.fail('No filter configured'),
        loadQuickAccess:()=>{},
        console:{error:()=>{}},
    };
    vm.createContext(ctx);
    vm.runInContext(extract('loadInventory._requestId = 0;',
        'function renderInventoryItem(item, opts = {}) {') + String.fromCharCode(10)
        + 'this.refreshPantry=loadInventory;',ctx);
    await ctx.refreshPantry();
    assert.equal(calls.length,1,'A tab refresh must make one GET not two');
    assert.equal(renders.length,0,'429 may not blank the pantry');
    assert.equal(ctx.currentInventory,old);

    response={success:true,inventory:full};
    await ctx.refreshPantry();
    assert.equal(calls.length,2,'A successful refresh also needs only one GET');
    assert.equal(ctx.currentInventory.length,1,'Current tab filtered locally');
    assert.equal(ctx.currentInventory[0].location,'frigo');
    assert.equal(ctx._allInventoryCache.length,2,'Full inventory remains available');
    assert.equal(renders.length,1);
    assert(js.includes("!params?.location") && js.includes("_offlineCacheSet(data.inventory)"),
        'A location-filtered request must not overwrite full offline cache');
    console.log('PASS 429 keeps visible rows; tab refresh uses one GET and preserves all locations');
}
async function testUseReadFailure() {
    const toasts=[];
    const ctx={
        currentProduct:{id:42},_useCurrentItems:[{id:71,product_id:42}],
        api:async()=>({success:false,error:'Too many requests'}),
        showToast:(...args)=>toasts.push(args),
        document:{getElementById:()=>assert.fail('429 cannot render zero-stock screen')},
        console:{error:()=>{}},
    };
    vm.createContext(ctx);
    vm.runInContext(extract('async function loadUseInventoryInfo() {',
        'function switchUseUnit(mode) {') + String.fromCharCode(10)
        + 'this.loadUse=loadUseInventoryInfo;',ctx);
    const status=await ctx.loadUse();
    assert.equal(status,false);
    assert.deepEqual(toasts,[['Too many requests','error']]);
    assert.equal(ctx._useCurrentItems.length,1,'Do not erase the known stock on a 429');
    console.log('PASS consume swipe does not treat 429 as exhausted product');
}
function testCenteredBackgrounds() {
    const block = cls => {
        const re=new RegExp('\\.'+cls+'\\s*\\{([^}]+)\\}');
        return css.match(re)?.[1] || '';
    };
    const base=block('inv-swipe-bg');
    const left=block('inv-swipe-bg-left');
    const right=block('inv-swipe-bg-right');
    assert(base.includes('width: 100px') && base.includes('justify-content: center')
        && base.includes('text-align: center'), 'Labels centered in a 100px revealed strip');
    assert(left.includes('right: 0') && right.includes('left: 0'),
        'Disposal on revealed right edge and consumption on revealed left edge');
    assert(left.includes('239, 68, 68') && right.includes('245, 158, 11'));
    console.log('PASS Verbrauchen and Entsorgen centered within their visible swipe strips');
}
(async()=>{
    await testDisposal429AndAuthoritativeAbsence();
    await testDisposalResponseBelongsToProduct();
    await testLatestSwipeWinsForSameProduct();
    await testPantryRefreshUsesOneRequestAndSurvives429();
    await testUseReadFailure();
    testCenteredBackgrounds();
    console.log('All transient iPhone swipe API failures and centering tests passed.');
})().catch(err=>{console.error(err);process.exitCode=1;});
