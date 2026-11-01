#!/usr/bin/env python3
"""Real HTTP/SQLite test for the opt-in offline conflict resolver.

Refuses real hosts and databases; tests revisions, atomic ledger writes,
replays, concurrent stale writers, ABA and all-or-nothing failures.
"""
import argparse
import json
import math
import sqlite3
import uuid
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--sandbox-root', required=True)
parser.add_argument('--base-url', required=True)
parser.add_argument('--second-url')
args = parser.parse_args()
root = Path(args.sandbox_root).resolve()
assert args.base_url.startswith(('http://127.0.0.1:', 'http://localhost:'))
assert args.second_url is None or args.second_url.startswith(
    ('http://127.0.0.1:', 'http://localhost:'))
assert (root / '.env').is_file()
assert 'EVERSHELF_SANDBOX=1' in (root / '.env').read_text()
db_path = root / 'data/evershelf.db'
assert db_path.is_file()


def req(action, payload=None, params='', base_url=None):
    url = (base_url or args.base_url).rstrip('/') + '/api/index.php?action=' + action + params
    body = None if payload is None else json.dumps(payload).encode()
    request = urllib.request.Request(url, body,
        headers={'X-EverShelf-Request': '1', 'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(request, timeout=25) as r:
            return r.status, json.load(r)
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read())
        except Exception:
            return e.code, {}


def snapshot(iid):
    status, result = req('inventory_conflict_snapshot', params=f'&id={iid}')
    assert status == 200 and result['success'], (status, result)
    return result['inventory']


def resolve(iid, pid, revision, mode, qty, base=None, key=None):
    p = dict(id=iid, product_id=pid, revision=revision, mode=mode,
             quantity=qty, _offline_op_id=key or str(uuid.uuid4()))
    if base is not None:
        p['base_quantity'] = base
    return p


def stock_ledger(iid, pid):
    with sqlite3.connect(db_path) as db:
        return (db.execute('SELECT quantity, revision, location FROM inventory WHERE id=?',
                          (iid,)).fetchone(),
                db.execute('SELECT type, quantity, notes FROM transactions WHERE product_id=?'
                           ' AND notes=? ORDER BY id', (pid, '[Manual correction]')).fetchall())


label = 'OfflineResolutionAudit ' + uuid.uuid4().hex[:8]
status, product = req('product_save', {'name': label, 'name_user_set': 1,
    'unit': 'conf', 'default_quantity': 690, 'package_unit': 'g', 'category': 'conserve'})
assert status == 200 and product['success'], (status, product)
pid = product['id']
status, added = req('inventory_add', {'product_id': pid, 'quantity': 6,
    'location': 'dispensa', 'unit': 'conf'})
assert status == 200 and added['success'], (status, added)
iid = snapshot(added['inventory_id'])['id']
first = snapshot(iid)
assert first['quantity'] == 6 and first['revision'] >= 0
assert first['unit'] == 'conf' and first['package_size'] == 690

# Server keep is NO REQUEST, only delete locally. Assert no stock or ledger side effects.
before = stock_ledger(iid, pid)
assert before[0][0] == 6 and before[1] == []
assert stock_ledger(iid, pid) == before
print('PASS keep-server: no stock update or ledger transaction')

# Simulate other device: updated-at can be same second, revision must change.
status, changed = req('inventory_update', {'id': iid, 'quantity': 8,
    'expected_inventory': {'quantity': 6, 'location': 'dispensa'}})
assert status == 200 and changed['success']
eight = snapshot(iid)
assert eight['revision'] > first['revision'] and eight['quantity'] == 8
status, changed = req('inventory_update', {'id': iid, 'quantity': 6})
assert status == 200 and changed['success']
back = snapshot(iid)
assert back['revision'] > eight['revision'] and back['quantity'] == 6
status, rejected = req('inventory_conflict_resolve',
    resolve(iid, pid, first['revision'], 'replace', 7))
assert status == 409 and rejected['error'] == 'inventory_conflict'
assert stock_ledger(iid, pid)[0][0] == 6
print('PASS monotonic ABA: stale version rejected despite matching quantity')

# Explicit set with one current version; fresh version must advance.
key = str(uuid.uuid4())
payload = resolve(iid, pid, back['revision'], 'replace', 7, key=key)
status, ok = req('inventory_conflict_resolve', payload)
assert status == 200 and ok['success'] and ok['quantity'] == 7
assert snapshot(iid)['revision'] > back['revision']
after_set = stock_ledger(iid, pid)
assert after_set[0][0] == 7 and after_set[1][-1] == ('in', 1, '[Manual correction]')
status, repeat = req('inventory_conflict_resolve', payload)
assert status == 200 and repeat['success'] and repeat['idempotent_replay']
assert stock_ledger(iid, pid) == after_set
status, reject_reuse = req('inventory_conflict_resolve', {**payload, 'quantity': 9})
assert status == 409 and reject_reuse['error'] == 'idempotency_conflict'
print('PASS replace: atomic stock+ledger+receipt, one write after replay')

# Rebase the difference on server 8: offline 6 -> 7 means +1 -> server 9.
status, changed = req('inventory_update', {'id': iid, 'quantity': 8})
assert status == 200 and changed['success']
now = snapshot(iid)
delta_payload = resolve(iid, pid, now['revision'], 'delta', 7, base=6)
status, ok = req('inventory_conflict_resolve', delta_payload)
assert status == 200 and ok['quantity'] == 9
after_delta = stock_ledger(iid, pid)
assert after_delta[0][0] == 9
status, repeated = req('inventory_conflict_resolve', delta_payload)
assert status == 200 and repeated['idempotent_replay']
assert stock_ledger(iid, pid) == after_delta
print('PASS delta: 8+(7-6)=9, replay never doubles stock')

# A stale third device and invalid negative final quantity must never write.
status, denied = req('inventory_conflict_resolve',
    resolve(iid, pid, now['revision'], 'replace', 4))
assert status == 409 and denied['error'] == 'inventory_conflict'
latest = snapshot(iid)
before_failure = stock_ledger(iid, pid)
status, denied = req('inventory_conflict_resolve',
    resolve(iid, pid, latest['revision'], 'delta', 0, base=100))
assert status == 400 and denied['error'] == 'invalid_resolved_quantity'
assert stock_ledger(iid, pid) == before_failure
print('PASS stale/negative resolution: rollback without stock or ledger side effects')

# Concurrent contenders at identical revision: just one may win.
latest = snapshot(iid)
one = resolve(iid, pid, latest['revision'], 'replace', 10)
two = resolve(iid, pid, latest['revision'], 'replace', 11)
with ThreadPoolExecutor(max_workers=2) as pool:
    jobs = [
        pool.submit(req, 'inventory_conflict_resolve', one),
        pool.submit(req, 'inventory_conflict_resolve', two, base_url=args.second_url),
    ]
    results = [job.result() for job in jobs]
assert sorted(code for code, _ in results) == [200, 409], results
winner = next(data for code, data in results if code == 200)
assert snapshot(iid)['quantity'] == winner['quantity']
assert len(stock_ledger(iid, pid)[1]) == len(before_failure[1]) + 1
print('PASS concurrent: exactly one versioned writer succeeds')

# Inject a receipt INSERT failure AFTER stock and ledger writes. SQLite must
# roll back all three, never exposing an unreceipted committed quantity.
latest = snapshot(iid)
before_broken_receipt = stock_ledger(iid, pid)
failure_key = 'receipt-fail-' + uuid.uuid4().hex
with sqlite3.connect(db_path) as db:
    db.execute('''CREATE TRIGGER audit_fail_resolution_receipt
        BEFORE INSERT ON offline_write_receipts
        WHEN NEW.action = 'inventory_conflict_resolve'
             AND NEW.operation_id = 'PLACEHOLDER'
        BEGIN SELECT RAISE(ABORT, 'forced_receipt_insert_failure'); END'''
        .replace('PLACEHOLDER', failure_key))
try:
    status, rejected = req('inventory_conflict_resolve',
        resolve(iid, pid, latest['revision'], 'replace', 21, key=failure_key))
    assert status == 500 and rejected['error'] == 'resolution_failed', (status, rejected)
    assert stock_ledger(iid, pid) == before_broken_receipt
    assert snapshot(iid)['revision'] == latest['revision']
finally:
    with sqlite3.connect(db_path) as db:
        db.execute('DROP TRIGGER IF EXISTS audit_fail_resolution_receipt')
print('PASS forced receipt failure: stock, revision and ledger rolled back together')

# An inventory row may be hidden by normal list when zero. It still has a snapshot.
latest = snapshot(iid)
status, ok = req('inventory_conflict_resolve',
    resolve(iid, pid, latest['revision'], 'replace', 0))
assert status == 200 and ok['success']
assert snapshot(iid)['quantity'] == 0

with sqlite3.connect(db_path) as db:
    assert db.execute('PRAGMA quick_check').fetchone()[0] == 'ok'
    assert db.execute('SELECT unit, default_quantity, package_unit FROM products WHERE id=?',
                      (pid,)).fetchone() == ('conf', 690, 'g')
    assert db.execute('SELECT COUNT(*) FROM offline_write_receipts WHERE action=?',
                      ('inventory_conflict_resolve',)).fetchone()[0] >= 4
print('PASS zero stock, settings untouched, SQLite integrity and receipts')
print('All real HTTP/SQLite offline conflict resolution tests passed.')
