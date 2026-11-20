#!/usr/bin/env node
'use strict';
// Issue #4: a 429 in product_get must not open a nameless Use form.
// Preserve deliberate offline behavior via the cached product snapshot.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const js=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
const a=js.indexOf('let _requestedUseEntryLocation = null;');
const b=js.indexOf('async function quickUse(',a);
assert(a>=0&&b>a);
const code=js.slice(a,b)+'\nthis.testOpenUse=_openUsePage;this.getProduct=()=>currentProduct;';
const item={id:74,product_id:42,name:'QA Nudeln Himmelblau',brand:'',category:'conserve',unit:'pz',default_quantity:1,package_unit:'pz',location:'dispensa',quantity:3};
async function run(productResponse,expected) {
 const toasts=[],pages=[],events=[];
 const ctx={
  currentProduct:null, currentInventory:[item],_allInventoryCache:[item],
  _scaleLatestWeight:null,
  showLoading:on=>events.push('loading-'+on),
  api:async act=>{assert.equal(act,'product_get');return productResponse;},
  showToast:(...args)=>toasts.push(args),
  document:{
   getElementById:id=>{assert.equal(id,'use-location');return {value:'dispensa'}},
   querySelectorAll:selector=>{assert.equal(selector,'#page-use .loc-btn');return [];}
  },
  renderUsePreview:()=>events.push('preview'),
  _cancelScaleAutoConfirm:()=>{},
  loadUseInventoryInfo:async()=>{events.push('inventory');return true;},
  showPage:page=>pages.push(page),
  console:{error:()=>{}},
  t:key=>key,
 };
 vm.createContext(ctx);
 vm.runInContext(code,ctx);
 await ctx.testOpenUse(42,'dispensa');
 if(expected==='fail') {
  assert.equal(toasts.length,1,'failed product_get should show explicit error');
  assert.deepEqual(pages,[],'cannot open incomplete form');
  assert.deepEqual(events,['loading-true','loading-false'],
    'cannot render stale/nameless preview or request more reads');
 } else {
  assert.deepEqual(toasts,[]);
  assert.deepEqual(pages,['use']);
  assert.equal(ctx.getProduct().name,item.name,'Use form must show product identity');
  assert(events.includes('preview') && events.includes('inventory'));
 }
 assert.equal(events.at(-1),'loading-false','loading spinner always cleared');
}
(async()=>{
 await run({success:false,error:'Zu viele Anfragen. Bitte später erneut versuchen.'},'fail');
 console.log('PASS product_get HTTP429 fails closed, no blank product page');
 await run({success:false,error:'network_error'},'fail');
 await run({success:false,error:'invalid_response'},'fail');
 await run({success:true},'fail');
 console.log('PASS 503/invalid JSON/empty product data do not open incomplete Use');
 await run({success:false,error:'offline',_offline:true},'success');
 console.log('PASS offline with cached row retains a correctly named Use form');
 await run({success:true,product:{id:42,name:item.name}},'success');
 console.log('PASS successful product_get still opens expected Use form');
})().catch(err=>{console.error(err);process.exitCode=1;});
