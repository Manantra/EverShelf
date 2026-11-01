#!/usr/bin/env python3
"""Verify true consumption, disposal and bookkeeping corrections remain disjoint.

Runs only against an explicitly marked loopback test sandbox.
"""
import argparse
import json
import sqlite3
import urllib.error
import urllib.request
import uuid
from pathlib import Path

ap = argparse.ArgumentParser()
ap.add_argument('--sandbox-root', required=True)
ap.add_argument('--base-url', required=True)
args = ap.parse_args()
root = Path(args.sandbox_root).resolve()
assert args.base_url.startswith(('http://127.0.0.1:', 'http://localhost:'))
assert 'EVERSHELF_SANDBOX=1' in (root / '.env').read_text()
dbpath = root / 'data/evershelf.db'
assert dbpath.is_file()


def request(action, payload=None):
    req = urllib.request.Request(
        args.base_url.rstrip('/') + '/api/index.php?action=' + action,
        json.dumps(payload).encode() if payload is not None else None,
        {'Content-Type': 'application/json', 'X-EverShelf-Request': '1'})
    try:
        with urllib.request.urlopen(req, timeout=20) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as ex:
        return ex.code, json.loads(ex.read())


def metrics():
    status, monthly = request('monthly_stats')
    assert status == 200 and monthly['success'], (status, monthly)
    status, overall = request('stats')
    assert status == 200, (status, overall)
    return monthly, overall


def verify(a, b, monthly_add=None, monthly_use=None, monthly_waste=None,
           trend_use=None, trend_waste=None):
    old, old_overall = a
    cur, cur_overall = b
    fields = {
        'items_added': monthly_add,
        'items_consumed': monthly_use,
        'items_wasted': monthly_waste,
    }
    for key, expected in fields.items():
        if expected is not None:
            actual = cur[key] - old[key]
            assert actual == expected, (key, actual, expected, old, cur)
    fields = {'used_30d': trend_use, 'wasted_30d': trend_waste}
    for key, expected in fields.items():
        if expected is not None:
            actual = cur_overall[key] - old_overall[key]
            assert actual == expected, (key, actual, expected, old_overall, cur_overall)


baseline = metrics()
code, product = request('product_save', {
    'name': 'StatsAudit-' + uuid.uuid4().hex[:12], 'name_user_set': 1,
    'unit': 'pz', 'category': 'conserve'})
assert code == 200 and product['success'], (code, product)
pid = product['id']
code, added = request('inventory_add', {
    'product_id': pid, 'quantity': 10, 'unit': 'pz', 'location': 'dispensa'})
assert code == 200 and added['success'], (code, added)
iid = added['inventory_id']
after_add = metrics()
verify(baseline, after_add, monthly_add=1, monthly_use=0, monthly_waste=0,
       trend_use=0, trend_waste=0)
print('PASS actual restock counted as added, not consumed or wasted')

code, used = request('inventory_use', {'product_id': pid, 'quantity': 1,
    'location': 'dispensa', 'notes': '', 'use_all': False})
assert code == 200 and used['success'], (code, used)
after_use = metrics()
verify(after_add, after_use, monthly_add=0, monthly_use=1, monthly_waste=0,
       trend_use=1, trend_waste=0)
print('PASS explicit use counted only as genuine consumption')

code, wasted = request('inventory_use', {'product_id': pid, 'quantity': 2,
    'location': 'dispensa', 'notes': 'Buttato|expired', 'use_all': False})
assert code == 200 and wasted['success'], (code, wasted)
after_waste = metrics()
verify(after_use, after_waste, monthly_add=0, monthly_use=0, monthly_waste=1,
       trend_use=0, trend_waste=1)
with sqlite3.connect(dbpath) as c:
    assert c.execute(
        'SELECT type,notes FROM transactions WHERE product_id=? ORDER BY id DESC LIMIT 1',
        (pid,)).fetchone() == ('waste', 'Buttato|expired')
print('PASS disposal with reason recorded as waste, never consumption')

code, adjusted = request('inventory_update', {'id': iid, 'quantity': 5})
assert code == 200 and adjusted['success'], (code, adjusted)
after_negative = metrics()
verify(after_waste, after_negative, monthly_add=0, monthly_use=0, monthly_waste=0,
       trend_use=0, trend_waste=0)
print('PASS manual stock decrease is not counted as use/waste')

code, adjusted = request('inventory_update', {'id': iid, 'quantity': 8})
assert code == 200 and adjusted['success'], (code, adjusted)
after_positive = metrics()
verify(after_negative, after_positive, monthly_add=0, monthly_use=0, monthly_waste=0,
       trend_use=0, trend_waste=0)
print('PASS manual stock increase is not counted as purchased')

code, moved = request('inventory_update', {'id': iid, 'location': 'frigo'})
assert code == 200 and moved['success'], (code, moved)
after_move = metrics()
verify(after_positive, after_move, monthly_add=0, monthly_use=0, monthly_waste=0,
       trend_use=0, trend_waste=0)
print('PASS location transfer does not create consumption or restock metrics')

# A historical undone disposal is excluded without deleting ledger rows.
with sqlite3.connect(dbpath) as c:
    c.execute('''INSERT INTO transactions
        (product_id,type,quantity,location,notes,undone)
        VALUES (?,'waste',1,'frigo','Buttato|expired',1)''', (pid,))
    c.execute('''INSERT INTO transactions
        (product_id,type,quantity,location,notes,undone)
        VALUES (?,'out',1,'frigo','',1)''', (pid,))
after_undone = metrics()
verify(after_move, after_undone, monthly_add=0, monthly_use=0, monthly_waste=0,
       trend_use=0, trend_waste=0)
with sqlite3.connect(dbpath) as c:
    assert c.execute('PRAGMA quick_check').fetchone()[0] == 'ok'
print('PASS undo markers never re-enter the statistics')
print('All real HTTP/SQLite consumption vs disposal statistics tests passed.')
