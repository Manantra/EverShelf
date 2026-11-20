#!/usr/bin/env node
'use strict';
/** Real loopback HTTP + SQLite test of the actual move modal functions.
 * Strictly refuses any non-sandbox root and non-loopback API URL.
 */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const {randomUUID}=require('node:crypto');
const rootArg=process.argv.indexOf('--sandbox-root');
const urlArg=process.argv.indexOf('--base-url');
assert(rootArg!==-1&&urlArg!==-1,'Require --sandbox-root and --base-url');
const root=path.resolve(process.argv[rootArg+1]);
const base=process.argv[urlArg+1].replace(/\/$/,'');
assert(/^http:\/\/(127\.0\.0\.1|localhost|\[::1\])(?::\d+)?$/.test(base),
    'Refuse non-loopback HTTP API');
assert(fs.readFileSync(path.join(root,'.env'),'utf8').split(/\r?\n/)
    .includes('EVERSHELF_SANDBOX=1'),'Refuse unmarked sandbox');
const dbfile=path.join(root,'data/evershelf.db');
const app=fs.readFileSync(path.join(__dirname,'../assets/js/app.js'),'utf8');
function extract(start,stop){
 const i=app.indexOf(start),j=app.indexOf(stop,i+start.length);
 assert(i>=0&&j>i,'Cannot extract '+start);
 return app.slice(i,j);
}
const code=[
 'let _prefMoveLocCache = {};',
 extract('const _PREF_MOVE_NEEDED = 1;', 'function _getPrefLocHistory('),
 extract('let _moveModalTimer = null;', 'let _addFormIdleTimer = null;'),
 extract('function startMoveModalCountdown(btnId, onExpire) {', 'async function submitUseAll() {'),
 extract('function showRecipeMoveModal(productId, fromLoc, remaining, openedId, wasVacuum) {',
   '/**\n * Extract tools/appliances'),
 'this.qa={showMoveAfterUseModal,confirmMoveAfterUse,showRecipeMoveModal,confirmRecipeMove,clearMoveModalTimer,setPrefs:v=>{_prefMoveLocCache=v}};'
].join('\n');
async function request(action,body=null) {
 const resp=await fetch(base+'/api/index.php?action='+action,{
  method:body===null?'GET':'POST',
  headers:body===null?{}:{'Content-Type':'application/json','X-EverShelf-Request':'1'},
  body:body===null?undefined:JSON.stringify(body),
 });
 const json=await resp.json();
 assert.equal(resp.status,200,JSON.stringify({action,json,status:resp.status}));
 if(action==='inventory_list') {
  assert(Array.isArray(json.inventory),'Legacy list endpoint must return inventory array');
 } else {
  assert.equal(json.success,true,JSON.stringify({action,json}));
 }
 return json;
}
function stock(pid) {
 const py=[
  'import sqlite3,json,sys',
  'db=sqlite3.connect(sys.argv[1])',
  "rows=db.execute('SELECT id,quantity,location,vacuum_sealed FROM inventory WHERE product_id=? ORDER BY id',(int(sys.argv[2]),)).fetchall()",
  "assert db.execute('PRAGMA quick_check').fetchone()[0]=='ok'",
  'print(json.dumps(rows))',
 ].join('\n');
 return JSON.parse(execFileSync('python3',['-c',py,dbfile,String(pid)],{encoding:'utf8'}));
}
async function createProduct() {
 const made=await request('product_save',{name:'QA MoveIntent '+randomUUID().slice(0,14),
   name_user_set:1,unit:'pz',category:'conserve',default_quantity:1});
 await request('inventory_add',{product_id:made.id,quantity:3,unit:'pz',
   location:'dispensa',vacuum_sealed:1});
 const rows=stock(made.id);
 assert.equal(rows.length,1);
 assert.equal(rows[0][1],3);
 return {id:made.id,row:rows[0][0]};
}
function browser(pid,{prefs={}}={}) {
 let now=0,seq=0;
 const timers=new Map(),settings=[],calls=[],toasts=[];
 const modal={innerHTML:'',style:{},addEventListener(){},removeEventListener(){}};
 const overlay={style:{display:'none'}}, button={style:{}},checkbox={checked:true};
 const els=new Map([['modal-content',modal],['modal-overlay',overlay],
   ['btn-move-stay',button],['move-vacuum-check',checkbox]]);
 const ctx={
  document:{getElementById: id=>els.get(id)||null},
  LOCATIONS:{dispensa:{icon:'📦',label:'Vorrat'},frigo:{icon:'❄️',label:'Kühlschrank'},
   freezer:{icon:'🧊',label:'Gefrierfach'}},
  performance:{now:()=>now},
  setTimeout:(cb,ms)=>{const id=++seq;timers.set(id,{when:now+ms,cb});return id},
  clearTimeout:id=>timers.delete(id),
  requestAnimationFrame:()=>++seq,cancelAnimationFrame:()=>{},
  _saveToServer:(key,val)=>settings.push([key,JSON.parse(JSON.stringify(val))]),
  api:async(action,params,method,body)=>{
    calls.push({action,body});
    if(action==='inventory_list')return request('inventory_list');
    return request(action,body||null);
  },
  closeModal:()=>{overlay.style.display='none';ctx.qa.clearMoveModalTimer();},
  showLoading:()=>{},showPage:()=>{},showToast:(msg,type)=>toasts.push({msg,type}),
  t:key=>key,escapeHtml:x=>x,
  currentProduct:{id:pid,name:'QA Butter',category:'conserve'},
  estimateExpiryDays:()=>2,estimateOpenedExpiryDays:()=>2,
  getVacuumExpiryDays:d=>d+1,addDays:()=> '2026-12-10',
  console:{error:(...args)=>toasts.push({type:'error',msg:String(args[0])})},
  JSON,Promise,Math,Object,
 };
 vm.createContext(ctx);vm.runInContext(code,ctx);ctx.qa.setPrefs(prefs);
 return {...ctx.qa,modal,overlay,calls,settings,toasts,
  advance:async ms=>{
    now+=ms;
    for(const[id,t]of [...timers])if(t.when<=now){timers.delete(id);t.cb()}
    await Promise.resolve();
  }};
}
(async()=>{
 const a=await createProduct(), before=stock(a.id);
 const idle=browser(a.id);
 idle.showMoveAfterUseModal({id:a.id,name:'QA Butter'},'dispensa',2,a.row,1,'pz');
 await idle.advance(15000);
 assert.deepEqual(stock(a.id),before,'Timer must not update stock/vacuum/expiry');
 assert.equal(idle.calls.length,0,'Timer never calls any inventory API');
 assert.equal(idle.settings.length,0,'Timer cannot teach server-side prefs');
 console.log('PASS real SQLite: 15-second normal timeout preserves location, quantity and vacuum');

 const old=browser(a.id,{prefs:{[`${a.id}|dispensa`]:['frigo']}});
 old.showMoveAfterUseModal({id:a.id,name:'QA Butter'},'dispensa',2,a.row,1,'pz');
 assert.equal(old.overlay.style.display,'flex');
 assert.deepEqual(stock(a.id),before,'Historic move preference cannot relocate stock');
 assert.equal(old.calls.length,0);
 console.log('PASS real SQLite: persisted preference does not silently move inventory');

 const manual=browser(a.id);
 manual.showMoveAfterUseModal({id:a.id,name:'QA Butter'},'dispensa',2,a.row,1,'pz');
 await manual.confirmMoveAfterUse(a.id,'dispensa','frigo',a.row);
 assert.equal(stock(a.id)[0][2],'frigo');
 assert.equal(stock(a.id)[0][1],3);
 assert.equal(manual.settings.length,1,'Confirmed move trains once');
 assert.equal(manual.settings[0][0],'pref_move_loc');
 console.log('PASS real HTTP/SQLite: manual move changes only the chosen inventory location');

 const b=await createProduct(), recipeBefore=stock(b.id);
 const recipeIdle=browser(b.id);
 recipeIdle.showRecipeMoveModal(b.id,'dispensa',2,b.row,true);
 await recipeIdle.advance(15000);
 assert.deepEqual(stock(b.id),recipeBefore);
 assert.equal(recipeIdle.calls.length,0);
 assert.equal(recipeIdle.settings.length,0);
 console.log('PASS real SQLite: recipe countdown cannot move product or change vacuum');

 const recipeManual=browser(b.id);
 recipeManual.showRecipeMoveModal(b.id,'dispensa',2,b.row,true);
 await recipeManual.confirmRecipeMove(b.id,'dispensa','frigo',b.row);
 assert.equal(stock(b.id)[0][2],'frigo');
 assert.equal(recipeManual.settings.length,1);
 console.log('PASS real HTTP/SQLite: explicit recipe move works and trains once');
 console.log('All real HTTP/SQLite move-after-use intent tests passed.');
})().catch(e=>{console.error(e);process.exitCode=1});
