#!/usr/bin/env node
'use strict';
/** Execute the real api()/queue/lock/replay functions, using a fake server and
 * Safari-like storage. Every lost reply is simulated AFTER the server commits.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const app = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
function extract(start, end) {
  const a = app.indexOf(start), b = app.indexOf(end, a + start.length);
  assert(a >= 0 && b > a, 'missing source ' + start);
  return app.slice(a,b);
}
const source = [
  "const _OFFLINE_QUEUE_KEY = 'qa-queue';",
  extract('const _OFFLINE_QUEUE_LIMIT = 100;', 'function _offlineShoppingCacheGet()'),
  extract('async function _offlineQueuePush(action, body, onlineIntent = false) {', '// ─── Offline API handler:'),
  extract('// ===== API HELPER =====', '// ===== PAGE NAVIGATION ====='),
  extract('async function _syncOfflineQueue() {', '// ─── Overlay show / hide'),
  'this.qa = { api, push: _offlineQueuePush, sync: _syncOfflineQueue, read: _offlineQueueReadStrict, onlineSig: _onlineWriteSignature };'
].join('\n');

function storage(data = new Map()) {
  const flags = { failRead: false, failWrite: false, block: null };
  return {
    data, flags,
    getItem(k) {
      if (flags.failRead) throw Error('Safari SecurityError');
      return data.get(k) ?? null;
    },
    setItem(k,v) {
      if (flags.failWrite || flags.block?.(k,String(v))) throw Error('Safari QuotaExceededError');
      data.set(k,String(v));
    }
  };
}
function webLocks() {
  let queue = Promise.resolve();
  return {request(_key,_opts,fn) {
    const next = queue.then(fn);
    queue = next.catch(()=>{});
    return next;
  }};
}
function server() {
  const receipts = new Map(), requests = [], committed = [];
  const state = { dropNext: false, holdNext: null };
  return {
    state, receipts, requests, committed,
    async fetch(url, opts) {
      const action = new URL(url, 'http://127.0.0.1').searchParams.get('action');
      const body = JSON.parse(opts.body || '{}');
      requests.push({action, body});
      const opId = body._offline_op_id;
      assert(opId && opId.length >= 12, 'protected writes always carry stable operation IDs');
      let result = receipts.get(opId);
      if (result) result = {...result, idempotent_replay:true};
      else {
        result = {success:true, action, testNumber:committed.length+1};
        receipts.set(opId,result);
        committed.push({action,body});
      }
      if (state.dropNext) {
        state.dropNext = false;
        throw Error('network vanished after server commit');
      }
      if (state.holdNext) {
        const hold = state.holdNext;
        state.holdNext = null;
        await new Promise(resolve => { hold.release = resolve; });
      }
      return {ok:true,status:200,json:async()=>result};
    }
  };
}
let seq = 0;
function browser(st, locks, back) {
  const notices = [], banners = [];
  const scope = {
    localStorage: st, navigator: {locks}, window:{},
    crypto: {randomUUID:()=>('qa-operation-0000000000000000-'+(++seq))},
    _demoMode:false, _offlineMode:false, _networkDown:false, _networkFailCount:0,
    _NETWORK_FAIL_THRESHOLD:3, API_BASE:'http://127.0.0.1:18080/api/index.php',
    fetch:(...args)=>back.fetch(...args),
    remoteLog:()=>{}, reportError:()=>{}, apiAuthHeaders:()=>({}),
    showToast:(message,type)=>notices.push({message,type}),
    _renderOfflineBanner:()=>banners.push(true),
    _hideNetworkOverlay:()=>{}, _showNetworkOverlay:()=>{},
    _offlineCacheSet:()=>{}, _offlineCacheSetSettings:()=>{}, _offlineProductsSet:()=>{},
    t:(key)=>key,
    Date,JSON,Promise,Math,console,
  };
  vm.createContext(scope);
  vm.runInContext(source,scope);
  return {...scope.qa, notices, banners};
}
function queued(st) {
  const raw = st.getItem('qa-queue');
  return raw ? JSON.parse(raw) : [];
}
const delayTick = ()=>new Promise(resolve=>setImmediate(resolve));

(async()=>{
  {
    const st = storage(), locks=webLocks(), back=server();
    const one=browser(st,locks,back);
    back.state.dropNext=true;
    const body={product_id:7,quantity:1,location:'dispensa'};
    const pending=await one.api('inventory_use',{},'POST',body);
    assert.equal(pending.success,false,'lost reply is not acknowledged as success');
    assert.equal(pending._queued,true);
    assert.equal(queued(st).length,1,'intent was durable BEFORE fetch');
    assert.equal(back.committed.length,1);
    const denied=await one.api('inventory_use',{},'POST',body);
    assert.equal(denied.success,false,'identical fresh attempt must not create new intent during uncertainty');
    assert.equal(back.committed.length,1);
    assert.equal(queued(st).length,1);
    const restarted=browser(st,locks,back);
    assert.equal(await restarted.sync(),1,'restarted PWA replays the original operation');
    assert.equal(back.committed.length,1,'replay uses the existing server receipt');
    assert.equal(queued(st).length,0,'acknowledged write is checkpointed');
    const deliberate=await restarted.api('inventory_use',{},'POST',body);
    assert.equal(deliberate.success,true,'new deliberate intent after ack is allowed');
    assert.equal(back.committed.length,2);
    assert.notEqual(back.committed[0].body._offline_op_id,
      back.committed[1].body._offline_op_id);
    console.log('PASS lost online reply -> durable replay, no double use, distinct later intent');
  }
  {
    const st=storage(), locks=webLocks(), back=server(), ui=browser(st,locks,back);
    const body={amount:42.15,currency:'€'};
    const [a,b,c]=await Promise.all([
      ui.api('spend_add',{},'POST',body),ui.api('spend_add',{},'POST',body),
      ui.api('spend_add',{},'POST',body)
    ]);
    assert.equal(back.committed.length,1,'three same-tab saves send one spending write');
    assert(a.success&&b.success&&c.success);
    assert.equal(queued(st).length,0);
    console.log('PASS triple-tap spend saves once, then acks queue');
  }
  {
    const st=storage(), locks=webLocks(), back=server();
    const left=browser(st,locks,back),right=browser(st,locks,back);
    const body={product_id:3,quantity:2,location:'frigo'};
    const hold={};back.state.holdNext=hold;
    const a=left.api('inventory_add',{},'POST',body);
    for(let i=0;i<20&&!hold.release;i++)await delayTick();
    assert(hold.release,'first tab must reach the network');
    const b=await right.api('inventory_add',{},'POST',body);
    assert.equal(b.success,false,'second tab recognizes pending first write');
    assert.equal(back.committed.length,1);
    hold.release();
    assert.equal((await a).success,true);
    assert.equal(queued(st).length,0);
    console.log('PASS shared Web Lock prevents duplicate cross-tab online intent');
  }
  {
    const st=storage(), back=server(), ui=browser(st,webLocks(),back);
    st.flags.failWrite=true;
    const r=await ui.api('inventory_use',{},'POST',{product_id:9,quantity:1,location:'dispensa'});
    assert.equal(r.success,false);
    assert.equal(back.requests.length,0,'cannot send a write whose receipt would be lost locally');
    st.flags.failWrite=false;
    st.setItem('qa-queue','{not-json');
    const bad=await ui.api('inventory_add',{},'POST',{product_id:9,quantity:1});
    assert.equal(bad.success,false);
    assert.equal(back.requests.length,0,'cannot overwrite corrupted Safari queue');
    assert.equal(st.getItem('qa-queue'),'{not-json');
    console.log('PASS unwriteable and corrupt Safari storage fail closed BEFORE network');
  }
  {
    const st=storage(),back=server(),ui=browser(st,webLocks(),back);
    const entry={action:'inventory_update',body:{id:17,quantity:6},_opId:'old'};
    st.setItem('qa-queue',JSON.stringify([entry]));
    const result=await ui.api('spend_add',{},'POST',{amount:8.50,currency:'€'});
    assert.equal(result.success,true);
    assert.equal(queued(st).length,1,'ack only removes its own operation, never old queued writes');
    assert.equal(queued(st)[0]._opId,'old');
    console.log('PASS online acknowledgement preserves previous offline edits');
  }
  {
    const st=storage(),back=server(),ui=browser(st,webLocks(),back);
    const actions=[['inventory_use',{product_id:7,quantity:1,location:'frigo'}],
                   ['spend_add',{amount:9.99,currency:'€'}]];
    for(const [a,b] of actions) {
      assert((await ui.push(a,b)).success);
    }
    assert.equal(await ui.sync(),2,'offline queue still replays both actions');
    assert.equal(back.committed.length,2);
    for(const record of back.committed) {
      assert(record.body._offline_op_id,'offline replay carries stable receipt ID for '+record.action);
    }
    console.log('PASS legacy offline queue now attaches stable IDs to use and spend');
  }
  {
    const st=storage(),back=server(),ui=browser(st,webLocks(),back);
    st.flags.block = (_key,value)=>JSON.parse(value).length===0;
    const result=await ui.api('inventory_use',{},'POST',{product_id:19,quantity:1,location:'dispensa'});
    assert.equal(result.success,true,'a known COMMIT does not become a false failure');
    assert.equal(queued(st).length,1,'receipt-protected intent remains for safe replay');
    assert(ui.notices.some(n=>n.message==='error.offline_storage_unavailable'));
    st.flags.block=null;
    const ui2=browser(st,webLocks(),back);
    assert.equal(await ui2.sync(),1);
    assert.equal(back.committed.length,1);
    console.log('PASS failed local ACK cannot cause duplicate server write');
  }
  {
    // An old offline create with temporary row ID and an equivalent online
    // add are the SAME unresolved intent, not permission to double-purchase.
    const st=storage(),back=server(),ui=browser(st,webLocks(),back);
    const body={product_id:21,quantity:2,location:'frigo'};
    assert((await ui.push('inventory_add',{...body,id:-123456})).success);
    const result=await ui.api('inventory_add',{},'POST',body);
    assert.equal(result.success,false);
    assert.equal(result._queued,true);
    assert.equal(back.requests.length,0,'pending offline create prevents duplicate online add');
    assert.equal(queued(st).length,1);
    console.log('PASS offline temporary row and online add share the same pending intent');
  }
  console.log('All durable online write, network-loss and Safari storage tests passed.');
})().catch(err=>{console.error(err);process.exitCode=1;});
