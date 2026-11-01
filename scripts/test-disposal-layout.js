#!/usr/bin/env node
'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const src=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const css=fs.readFileSync(path.join(__dirname,'../assets/css/style.css'),'utf8');
const de=JSON.parse(fs.readFileSync(path.join(__dirname,'../translations/de.json'),'utf8'));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
function cut(start,end) {
    const i=src.indexOf(start),j=src.indexOf(end,i+start.length);
    assert(i>=0 && j>i,start+' / '+end);
    return src.slice(i,j);
}
const helpers=cut('function _renderUseStockSummary(items) {','function _updateUseHeroMeta(items) {');
const modalCode=cut('let _throwFormRequestId = 0;','function selectThrowLocation(');
function setup(rows) {
    const events={},calls=[];
    const modal={
        innerHTML:'',
        addEventListener(name,handler){events[name]=handler;},
    };
    const overlay={style:{display:'none'}};
    const quantity={value:'1',focused:false,focus(){this.focused=true;}};
    const loc={value:'dispensa'};
    const elements={
        'modal-content':modal, 'modal-overlay':overlay,
        'throw-quantity':quantity,'throw-location':loc
    };
    const context={
        currentProduct:{id:42,name:'Demo',category:'conserve',image_url:null,unit:'conf'},
        _collationLocale:()=> 'de-DE',
        t:k=>k==='use.available'?'Verfügbar':k==='use.weight_per_package'?'je Packung':k==='use.weight_total'?'insgesamt':k==='use.throw_all_action'?'🗑️ Alles entsorgen':k==='use.throw_all_scope'?'Alle Lagerorte':k,
        api:async(action)=>{calls.push(['GET',action]);return {success:true,inventory:rows};},
        LOCATIONS:{
            dispensa:{icon:'📦',label:'Vorratskammer'},
            frigo:{icon:'🧊',label:'Kühlschrank'},
        },
        CATEGORY_ICONS:{conserve:'📦'},
        mapToLocalCategory:()=> 'conserve',
        escapeHtml:String,
        stripHtml:String,
        formatQuantity:(n,u)=>String(n)+' '+(u==='conf'?'Packungen':u),
        localizedPackageUnitLabel:n=>Number(n)===1?'Packung':'Packungen',
        getUnitDisplayLabel:()=> 'Packung',
        setQtyInputUnitLabel:()=>{},
        document:{getElementById:id=>elements[id]||null},
        showToast:(...v)=>calls.push(['toast',...v]),
        closeModal:()=>calls.push(['close']),
        showPage:()=>calls.push(['navigate']),
        showLoading:()=>{},
        console:{warn:()=>{}},
    };
    vm.createContext(context);
    vm.runInContext(helpers+'\n'+modalCode
        +'\nthis.openDispose=showThrowForm;',context);
    return {context,modal,overlay,quantity,loc,calls,events,elements};
}
const item=(id,qty,location,size=690)=>({
    id,product_id:42,quantity:qty,location,
    unit:'conf',default_quantity:size,package_unit:'g',
});
async function singleLocationLayout() {
    const state=setup([item(71,14,'dispensa')]);
    state.context.openDispose('dispensa');
    await tick();
    const html=state.modal.innerHTML;
    assert.equal(state.overlay.style.display,'flex');
    assert.equal((html.match(/14 Packungen/g)||[]).length,1,
        'Show total 14 packages exactly once');
    assert(html.includes('690 g je Packung · 9.660 g insgesamt'));
    assert(html.includes('Vorratskammer'));
    assert(!html.includes('inventory-status-bar'),'No old duplicate stock bar');
    assert(!html.includes('id="throw-location-selector"'),
        'One location means no redundant location chooser');
    assert(html.includes('id="throw-location" value="dispensa"'));
    assert(html.includes('dispose-stock-overview') && html.includes('use-stock-summary'));
    assert(html.includes('Wie viel entsorgen?') || html.includes('use.throw_qty_label'));
    const partial=html.indexOf('onclick="throwPartial()"');
    const all=html.indexOf('onclick="throwAll()"');
    assert(partial>=0 && all>partial,'Full disposal must come after normal amount action');
    assert(html.includes('Alle Lagerorte'),'Bottom full-disposal action clarifies scope');
    assert(!html.includes('ALLES entsorgen (14 Packungen)'),
        'No repeated total in full-disposal button');
    assert(state.calls.every(c=>c[0]!=='POST'),'Opening form never posts a write');
    console.log('PASS one compact 14 x 690g overview, amount first and full disposal last');
}
async function multiLocationLayout(){
    const state=setup([item(11,2,'dispensa'),item(71,3,'frigo',500),item(12,1,'dispensa')]);
    state.context.openDispose('frigo');await tick();
    const html=state.modal.innerHTML;
    assert(html.includes('6 Packungen'));
    assert(html.includes('3.570 g insgesamt'),
        'Total must be calculated from per-row package sizes');
    assert(!html.includes('690 g je Packung'),'Mixed package sizes cannot share one size');
    assert(html.includes('id="throw-location-selector"'));
    assert(html.includes('value="frigo"'));
    assert.equal((html.match(/selectThrowLocation\(this, 'dispensa'\)/g)||[]).length,1,
        'Multiple rows at the same storage location produce one selection');
    assert.equal((html.match(/selectThrowLocation\(this, 'frigo'\)/g)||[]).length,1);
    assert(html.includes('Vorratskammer') && html.includes('Kühlschrank'));
    console.log('PASS location chooser only for multiple locations, selected row preferred');
}
async function explicitConfirmation(){
    const handlers={},writes=[];
    const nodes={
      'modal-content':{innerHTML:''},
      'modal-overlay':{style:{display:'none'}},
      '_destCancelBtn':{addEventListener:(ev,cb)=>handlers.cancel=cb},
      '_destConfirmBtn':{addEventListener:(ev,cb)=>handlers.confirm=cb},
    };
    const ctx={
        document:{getElementById:id=>nodes[id]},
        escapeHtml:String,t:x=>x,
        closeModal:()=>writes.push('close'),
        setTimeout:()=>assert.fail('No automatic confirmation timer'),
    };
    vm.createContext(ctx);
    vm.runInContext(cut('function _showDestructiveConfirm(title, msg, onConfirm, confirmLabel) {',
        'async function throwAll() {')+'\nthis.ask=_showDestructiveConfirm;',ctx);
    ctx.ask('Alles?', 'Alle Lagerorte?',()=>writes.push('dispose'));
    assert(!writes.includes('dispose'),'Opening does not confirm anything');
    handlers.cancel();assert(!writes.includes('dispose'),'Cancel must not dispose');
    writes.length=0;
    ctx.ask('Alles?', 'Alle Lagerorte?',()=>writes.push('dispose'));
    handlers.confirm();assert.deepEqual(writes,['close','dispose'],
        'Only clicking confirm performs the next step');
    console.log('PASS full disposal requires explicit tap, never timer or cancel');
}
async function invalidAmount(){
    const ctx={
        document:{getElementById:id=>{
            if(id==='throw-quantity')return ctx.qty;
            if(id==='throw-location')return {value:'frigo'};
            return null;
        }},
        qty:{value:'0',focus(){this.focused=true;}},
        showToast:(...args)=>ctx.toasts.push(args),
        closeModal:()=>ctx.closed++,
        _inventoryWaste:()=>assert.fail('Invalid quantity must not write'),
        toasts:[],closed:0,t:k=>k,
    };
    vm.createContext(ctx);
    vm.runInContext(cut('async function throwPartial() {',
        'function toggleActionEdit() {')+'\nthis.submitPartial=throwPartial;',ctx);
    await ctx.submitPartial();
    assert.equal(ctx.closed,0);
    assert.equal(ctx.qty.focused,true);
    assert.deepEqual(ctx.toasts,[['use.error_qty_required','error']]);
    ctx.qty.value='';
    await ctx.submitPartial();
    assert.equal(ctx.toasts.length,2,'Empty amount is never interpreted as one pack');
    console.log('PASS blank/zero amount never silently disposes one package');
}
function testLocalizedAndCSS(){
    for(const locale of ['de','en','it','es','fr','zh']){
        const data=JSON.parse(fs.readFileSync(path.join(__dirname,'../translations/'+locale+'.json'),'utf8'));
        assert(data.use.throw_all_action && data.use.throw_all_scope);
        assert(!data.use.throw_all_action.includes('{qty}'));
    }
    assert(css.includes('.dispose-all-option') && css.includes('.dispose-stock-overview'),
        'Dedicated styling for bottom action and compact stock');
    assert.equal(de.use.throw_all_action,'🗑️ Alles entsorgen');
    console.log('PASS six translations, German action naming, dedicated responsive CSS');
}
(async()=>{
 await singleLocationLayout();
 await multiLocationLayout();
 await explicitConfirmation();
 await invalidAmount();
 testLocalizedAndCSS();
 console.log('All compact disposal form regression tests passed.');
})().catch(e=>{console.error(e);process.exitCode=1;});
