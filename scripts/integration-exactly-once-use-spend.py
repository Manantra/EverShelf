#!/usr/bin/env python3
"""Issue #29. Real HTTP/SQLite/JSON-file replay tests, synthetic loopback only."""
import argparse
import concurrent.futures
import json
import sqlite3
import uuid
import time
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

p = argparse.ArgumentParser()
p.add_argument('--sandbox-root', type=Path, required=True)
p.add_argument('--base-url', required=True)
p.add_argument('--second-url', required=True)
args = p.parse_args()
root = args.sandbox_root.resolve(strict=True)
assert (root / '.env').is_file() and 'EVERSHELF_SANDBOX=1' in (root / '.env').read_text()
for u in (args.base_url, args.second_url):
    parsed = urlparse(u)
    assert parsed.scheme == 'http' and parsed.hostname in ('127.0.0.1', 'localhost', '::1')
dbfile = root/'data/evershelf.db'
spendfile = root/'data/shopping_spend.json'

def req(url, action, data):
    request = Request(url.rstrip('/') + '/api/index.php?action=' + action,
        data=json.dumps(data).encode(), method='POST',
        headers={'Content-Type':'application/json','X-EverShelf-Request':'1'})
    try:
        with urlopen(request, timeout=35) as resp:
            return resp.status, json.load(resp)
    except HTTPError as e:
        return e.code,json.loads(e.read())

def receipt_state(pid, key):
    with sqlite3.connect(dbfile) as db:
        stock=db.execute('SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id=?', (pid,)).fetchone()[0]
        uses=db.execute("SELECT COUNT(*) FROM transactions WHERE product_id=? AND type='out'",(pid,)).fetchone()[0]
        receipt=db.execute("SELECT action FROM offline_write_receipts WHERE operation_id=?",(key,)).fetchone()
        assert db.execute("PRAGMA quick_check").fetchone()[0]=='ok'
        return stock,uses,receipt

def retry_busy(url, action, body):
    for delay in (.0,.1,.2,.4,.8):
        if delay: time.sleep(delay)
        code,response=req(url,action,body)
        if code!=503: return code,response
    return code,response

def new_product(quantity=8):
    code,r=req(args.base_url,'product_save',{'name':'QA ExactlyOnce '+uuid.uuid4().hex[:12],
         'unit':'pz','category':'conserve','name_user_set':1})
    assert code==200 and r.get('success'),(code,r)
    pid=r['id']
    code,r=req(args.base_url,'inventory_add',{'product_id':pid,'quantity':quantity,'unit':'pz','location':'dispensa'})
    assert code==200 and r.get('success'),(code,r)
    return pid

pid=new_product()
key=uuid.uuid4().hex
use={'product_id':pid,'quantity':1,'location':'dispensa','_offline_op_id':key}
code,a=req(args.base_url,'inventory_use',use)
code2,b=req(args.second_url,'inventory_use',use)
assert code==code2==200 and a.get('success') and b.get('success'),(code,a,code2,b)
assert b.get('idempotent_replay') is True, b
assert receipt_state(pid,key)==(7,1,('inventory_use',)),receipt_state(pid,key)
print('PASS use replay: a lost HTTP reply never deducts twice')

code,c=req(args.base_url,'inventory_use',{**use,'_offline_op_id':uuid.uuid4().hex})
assert code==200 and c.get('success'),(code,c)
assert receipt_state(pid,key)[0:2]==(6,2),receipt_state(pid,key)
print('PASS use distinct user intents: identical quantity twice is allowed')

code,c=req(args.base_url,'inventory_use',{**use,'quantity':2})
assert code==409 and c.get('error')=='idempotency_conflict',(code,c)
assert receipt_state(pid,key)[0:2]==(6,2),receipt_state(pid,key)
print('PASS use payload reuse is rejected')

pid=new_product()
key=uuid.uuid4().hex
recipe={'product_id':pid,'quantity':1,'location':'dispensa','notes':'Ricetta: QA',
        '_offline_op_id':key}
urls=[args.base_url,args.second_url]
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    results=list(pool.map(lambda idx: retry_busy(urls[idx%2],'inventory_use',recipe),range(6)))
assert all(c==200 and data.get('success') for c,data in results),results
assert sum(1 for _,d in results if d.get('idempotent_replay'))==5,results
assert receipt_state(pid,key)==(7,1,('inventory_use',)),receipt_state(pid,key)
print('PASS six concurrent recipe replays: one inventory+ledger write')

pid=new_product()
key=uuid.uuid4().hex
use={'product_id':pid,'quantity':1,'location':'dispensa','_offline_op_id':key}
trigger='qa_receipt_'+uuid.uuid4().hex[:10]
with sqlite3.connect(dbfile) as db:
    db.execute(f"CREATE TRIGGER {trigger} BEFORE INSERT ON offline_write_receipts "
               f"WHEN NEW.operation_id='{key}' BEGIN SELECT RAISE(ABORT,'qa receipt failed'); END")
try:
    status,rejected=req(args.base_url,'inventory_use',use)
    assert status>=400 and rejected.get('success') is False,(status,rejected)
    assert receipt_state(pid,key)==(8,0,None),receipt_state(pid,key)
finally:
    with sqlite3.connect(dbfile) as db: db.execute(f'DROP TRIGGER IF EXISTS {trigger}')
code,done=req(args.base_url,'inventory_use',use)
assert code==200 and done.get('success'),(code,done)
assert receipt_state(pid,key)==(7,1,('inventory_use',))
print('PASS use receipt-insert failure rolls back stock+ledger')

pid=new_product(3)
key=uuid.uuid4().hex
complete={'product_id':pid, 'use_all':True, 'location':'__all__', '_offline_op_id':key}
code,done=req(args.base_url,'inventory_use',complete)
assert code==200 and done.get('success'),(code,done)
code,replay=req(args.second_url,'inventory_use',complete)
assert code==200 and replay.get('idempotent_replay') is True,(code,replay)
assert receipt_state(pid,key)[0] == 0,receipt_state(pid,key)
print('PASS use_all completes once, repeated receipt does not re-add shopping or ledger')

code,bad=req(args.base_url,'inventory_use',
    {'product_id':pid,'quantity':1,'_offline_op_id':'bad!'})
assert code==400 and bad.get('error')=='invalid_offline_op_id',(code,bad)
print('PASS use rejects malformed stable operation IDs')

# Spend history is historically a JSON file, not SQLite; the receipt must live
# inside each durable file entry, under the same cross-worker file lock.
spendkey=uuid.uuid4().hex
amount={'amount':42.15,'currency':'€','_offline_op_id':spendkey}
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
    results=list(pool.map(lambda idx: req(urls[idx%2],'spend_add',amount),range(6)))
assert all(c==200 and d.get('success') for c,d in results),results
assert sum(1 for _,d in results if d.get('idempotent_replay'))==5,results
data=json.loads(spendfile.read_text())
ours=[x for x in data if x.get('_offline_op_id')==spendkey]
assert len(ours)==1,ours
print('PASS six concurrent spend replays: exactly one file entry')

code,d=req(args.base_url,'spend_add',{**amount,'amount':99.99})
assert code==409 and d.get('error')=='idempotency_conflict',(code,d)
newkey=uuid.uuid4().hex
code,e=req(args.second_url,'spend_add',{**amount,'_offline_op_id':newkey})
assert code==200 and e.get('success'),(code,e)
data=json.loads(spendfile.read_text())
assert len([x for x in data if x.get('_offline_op_id') in (spendkey,newkey)])==2
print('PASS spend repeated key conflicts; new intent is an independent purchase')

# Never silently turn a damaged file into an empty history.
original=spendfile.read_bytes()
try:
    spendfile.write_text('{corrupt_json',encoding='utf-8')
    code,refused=req(args.base_url,'spend_add',
        {'amount':13.12,'currency':'€','_offline_op_id':uuid.uuid4().hex})
    assert code>=400 and refused.get('success') is False,(code,refused)
    assert spendfile.read_text()=='{corrupt_json'
    print('PASS spend corruption refuses new entries without destroying existing file')
finally:
    spendfile.write_bytes(original)

code,invalid=req(args.base_url,'spend_add',
    {'amount':8,'currency':'€','_offline_op_id':'bad!'})
assert code==400 and invalid.get('error')=='invalid_offline_op_id',(code,invalid)
print('PASS spend malformed ID refused before touching history')
print('PASS issue #29 use/spend integration checks')
