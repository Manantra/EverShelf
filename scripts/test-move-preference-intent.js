#!/usr/bin/env node
'use strict';
/** Runs the real move/recipe dialog functions under a deterministic fake
 * 15-second clock. No browser, live server, inventory or iPhone is touched.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function extract(start, stop) {
    const i = app.indexOf(start);
    const j = app.indexOf(stop, i + start.length);
    assert(i >= 0 && j > i, 'Required real application source not found: ' + start);
    return app.slice(i, j);
}
const source = [
    'let _prefMoveLocCache = {};',
    extract('const _PREF_MOVE_NEEDED = 1;', 'function _getPrefLocHistory('),
    extract('let _moveModalTimer = null;', 'let _addFormIdleTimer = null;'),
    extract('function startMoveModalCountdown(btnId, onExpire) {', 'async function submitUseAll() {'),
    extract('function showRecipeMoveModal(productId, fromLoc, remaining, openedId, wasVacuum) {',
        '/**\n * Extract tools/appliances'),
    `this.test = {
        showMoveAfterUseModal, _saveVacuumAndStay, confirmMoveAfterUse,
        showRecipeMoveModal, _recipeMoveCancelStay, confirmRecipeMove,
        getPrefs: () => _prefMoveLocCache,
        setPrefs: value => { _prefMoveLocCache = value; },
        pending: () => _pendingMoveCtx,
        clearMoveModalTimer,
    };`,
].join('\n');
function simulator({ prefs = {}, updateOK = true, updateQueued = false, staleId = false } = {}) {
    let now = 0, serial = 0;
    const timers = new Map(), writes = [], saves = [], pages = [], toasts = [], errors = [];
    const touchListeners = new Map();
    const modal = {
        innerHTML: '',
        style: {},
        addEventListener(type, handler) { touchListeners.set(type, handler); },
        removeEventListener(type, handler) {
            if (touchListeners.get(type) === handler) touchListeners.delete(type);
        },
    };
    const overlay = { style: { display: 'none' } };
    const btn = { style: {} };
    const checkbox = { checked: true };
    const elements = new Map([
        ['modal-content', modal], ['modal-overlay', overlay],
        ['btn-move-stay', btn], ['move-vacuum-check', checkbox],
    ]);
    const ctx = {
        LOCATIONS: {
            dispensa: { icon: '🥫', label: 'Vorrat' },
            frigo: { icon: '❄️', label: 'Kühlschrank' },
            freezer: { icon: '🧊', label: 'Gefrierfach' },
        },
        document: { getElementById: name => elements.get(name) || null },
        performance: { now: () => now },
        setTimeout: (callback, delay) => {
            const id = ++serial;
            timers.set(id, { at: now + delay, callback });
            return id;
        },
        clearTimeout: id => timers.delete(id),
        requestAnimationFrame: () => ++serial,
        cancelAnimationFrame: () => {},
        _saveToServer: (key, value) => saves.push({ key, value: JSON.parse(JSON.stringify(value)) }),
        api: async (action, _params, method, body) => {
            writes.push({ action, method, body });
            if (action === 'inventory_list') return {
                inventory: [{ id: 101, product_id: staleId ? 99 : 44, location: 'dispensa',
                    quantity: 1, name: 'Testbutter', category: 'conserve',
                    expiry_user_set: 1, vacuum_sealed: 1 }] };
            return { success: updateOK, _queued: updateQueued };
        },
        closeModal: () => {
            overlay.style.display = 'none';
            ctx.test.clearMoveModalTimer();
        },
        showLoading: () => {}, showPage: p => pages.push(p),
        showToast: (msg, level) => toasts.push({ msg, level }),
        t: (key) => key,
        escapeHtml: x => x, currentProduct: { id: 44, name: 'Testbutter', category: 'conserve' },
        estimateOpenedExpiryDays: () => 2, estimateExpiryDays: () => 2,
        getVacuumExpiryDays: n => n + 2, addDays: n => '2026-11-' + n,
        console: { error: (...args) => errors.push(args) }, JSON, Promise, Math, Object,
    };
    vm.createContext(ctx);
    vm.runInContext(source, ctx);
    ctx.test.setPrefs(prefs);
    const advance = async ms => {
        now += ms;
        for (const [id,timer] of [...timers]) {
            if (timer.at <= now) {
                timers.delete(id);
                await timer.callback();
                await Promise.resolve();
            }
        }
    };
    return { ...ctx.test, advance, modal, overlay, checkbox, writes, saves, pages, toasts, errors,
        touch: () => touchListeners.get('pointerdown')?.() };
}
async function autoDefaultDoesNotTrainOrWrite() {
    const s = simulator();
    s.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    assert.equal(s.overlay.style.display,'flex');
    await s.advance(14999);
    assert.equal(s.saves.length,0,'Timer has not yet expired');
    await s.advance(1);
    assert.equal(s.saves.length,0,'Countdown must NOT train pref_move_loc');
    assert.equal(s.writes.length,0,'Countdown must NOT change vacuum or inventory');
    assert.equal(s.pending(),null,'Auto-close clears pending dialog context');
    console.log('PASS ordinary 15s timeout neither trains nor changes stock/vacuum');
}
async function explicitStayTrainsAndPersistsCorrectValue() {
    const s = simulator();
    s.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    await s._saveVacuumAndStay(101);
    assert.equal(s.getPrefs()['44|dispensa'].at(-1),'dispensa');
    assert.equal(s.saves.length,1);
    assert.equal(s.writes.length,1);
    assert.equal(s.writes[0].body.vacuum_sealed,1);
    await s.advance(20000);
    assert.equal(s.saves.length,1,'Manual click cancels timeout, no double-learning');
    console.log('PASS explicit stay learns once and preserves checked vacuum setting');
}
async function silentPreferenceNeverRelocates() {
    const s=simulator({prefs:{'44|dispensa':['frigo']}});
    s.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    assert.equal(s.overlay.style.display,'flex',
        'A saved move preference must offer confirmation, not relocate silently');
    assert.equal(s.writes.length,0,'No inventory_update without explicit move');
    assert.equal(s.saves.length,0,'An auto suggestion must not learn again');
    console.log('PASS preference does not silently move inventory to another location');
}
async function storedStayNeverChangesVacuum() {
    const s=simulator({prefs:{'44|dispensa':['dispensa']}});
    s.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    await Promise.resolve();
    assert.equal(s.writes.length,0,'Auto-applied stay must not clear existing vacuum');
    assert.equal(s.saves.length,0,'Auto-applied stay must not reinforce itself');
    console.log('PASS remembered stay does not write hidden vacuum changes');
}
async function confirmedMoveLearnsOnlyWhenSaved() {
    for (const good of [false,true]) {
        const s=simulator({updateOK:good});
        s.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
        await s.confirmMoveAfterUse(44,'dispensa','frigo',101);
        assert.equal(s.saves.length,good?1:0,
            'Failed inventory_update must not be learned as a move');
        if (good) assert.equal(s.getPrefs()['44|dispensa'].at(-1),'frigo');
    }
    console.log('PASS only confirmed manual move updates preference');
}
async function dismissAndTouchAreNotLearning() {
    const x=simulator();
    x.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    assert.match(x.modal.innerHTML, /_saveVacuumAndStay\(101, true\)/,
        'X button sends the non-consent flag');
    await x._saveVacuumAndStay(101,true);
    await x.advance(25000);
    assert.equal(x.saves.length,0);
    assert.equal(x.writes.length,0);
    const touched=simulator();
    touched.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    touched.touch(); // Interaction cancels the timer, not the user's choice
    await touched.advance(20000);
    assert.equal(touched.overlay.style.display,'flex',
        'Pointer input cancels timer, leaving modal open for an explicit choice');
    assert.equal(touched.saves.length,0);
    assert.equal(touched.writes.length,0);
    console.log('PASS dismissal X and pointerdown do not learn or auto-write');
}
async function staleAndQueuedMovesNeverTrain() {
    const stale=simulator({staleId:true});
    stale.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    await stale.confirmMoveAfterUse(44,'dispensa','frigo',101);
    assert.equal(stale.saves.length,0);
    assert.equal(stale.writes.filter(w=>w.action==='inventory_update').length,0,
        'Wrong product ID prevents touching a stale or unrelated inventory row');
    const queued=simulator({updateQueued:true});
    queued.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,101,1,'pz');
    await queued.confirmMoveAfterUse(44,'dispensa','frigo',101);
    assert.equal(queued.saves.length,0,'A queued/unconfirmed move does not train');
    assert.equal(queued.toasts.some(t=>t.level==='success'),false,
        'No success toast before a durable server ack');
    assert.equal(queued.toasts.some(t=>t.level==='warning'),true,
        'A queued move gets a pending warning, not an erroneous failure');
    const recipeQueued=simulator({updateQueued:true});
    recipeQueued.showRecipeMoveModal(44,'dispensa',1,101,true);
    await recipeQueued.confirmRecipeMove(44,'dispensa','frigo',101);
    assert.equal(recipeQueued.saves.length,0);
    assert.equal(recipeQueued.toasts.some(t=>t.level==='warning'),true);
    const other=simulator();
    other.showMoveAfterUseModal({id:44,name:'Testbutter'},'dispensa',1,0,0,'pz');
    await other.confirmMoveAfterUse(44,'dispensa','freezer',0);
    assert.equal(other.writes.filter(w=>w.action==='inventory_update').length,1,
        'Explicit selection moves the correct row even without openedId');
    assert.equal(other.writes.find(w=>w.action==='inventory_update').body.location,'freezer');
    assert.equal(other.saves.length,1);
    console.log('PASS stale IDs, unconfirmed writes and alternate locations guarded');
}
async function recipeAutoAndManualIntent() {
    const auto=simulator();
    auto.showRecipeMoveModal(44,'dispensa',1,101,true);
    await auto.advance(15000);
    assert.equal(auto.saves.length,0,'Recipe timeout must not train');
    assert.equal(auto.writes.length,0,'Recipe countdown never modifies stock');
    assert.equal(auto.pending(),null);
    const tap=simulator();
    tap.showRecipeMoveModal(44,'dispensa',1,101,true);
    tap._recipeMoveCancelStay(44,'dispensa',101);
    assert.equal(tap.saves.length,1,'Explicit recipe stay can train');
    const recipeDismiss=simulator();
    recipeDismiss.showRecipeMoveModal(44,'dispensa',1,101,true);
    assert.match(recipeDismiss.modal.innerHTML,
        /_recipeMoveCancelStay\(44, 'dispensa', 101, true\)/);
    recipeDismiss._recipeMoveCancelStay(44,'dispensa',101,true);
    assert.equal(recipeDismiss.saves.length,0,'Recipe X is not a trained stay');
    const preferredStay=simulator({prefs:{'44|dispensa':['dispensa']}});
    preferredStay.showRecipeMoveModal(44,'dispensa',1,101,true);
    assert.equal(preferredStay.writes.length,0);
    assert.equal(preferredStay.saves.length,0);
    const preferred=simulator({prefs:{'44|dispensa':['freezer']}});
    preferred.showRecipeMoveModal(44,'dispensa',1,101,true);
    assert.equal(preferred.overlay.style.display,'flex');
    assert.equal(preferred.writes.length,0,'Recipe preference must not silently move');
    const failure=simulator({updateOK:false});
    failure.showRecipeMoveModal(44,'dispensa',1,101,true);
    await failure.confirmRecipeMove(44,'dispensa','frigo',101);
    assert.equal(failure.saves.length,0,'Failed recipe move must not train');
    const success=simulator();
    success.showRecipeMoveModal(44,'dispensa',1,101,true);
    await success.confirmRecipeMove(44,'dispensa','frigo',101);
    assert.equal(success.saves.length,1,'Confirmed recipe move learns');
    console.log('PASS recipe timeout/preference/manual save branches');
}
(async()=>{
    await autoDefaultDoesNotTrainOrWrite();
    await explicitStayTrainsAndPersistsCorrectValue();
    await silentPreferenceNeverRelocates();
    await storedStayNeverChangesVacuum();
    await confirmedMoveLearnsOnlyWhenSaved();
    await dismissAndTouchAreNotLearning();
    await staleAndQueuedMovesNeverTrain();
    await recipeAutoAndManualIntent();
    console.log('All move intent, fake-clock and recipe regressions passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
