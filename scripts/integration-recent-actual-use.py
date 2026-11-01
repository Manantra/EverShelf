#!/usr/bin/env python3
"""Check that inventory corrections do not masquerade as recently used products.

Real HTTP/SQLite API, strictly restricted to a marked test sandbox.
"""
import argparse
import json
import sqlite3
import urllib.request
import urllib.error
import uuid
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--sandbox-root', required=True)
parser.add_argument('--base-url', required=True)
args = parser.parse_args()
root = Path(args.sandbox_root).resolve()
assert args.base_url.startswith(('http://127.0.0.1:', 'http://localhost:'))
assert (root / '.env').is_file() and 'EVERSHELF_SANDBOX=1' in (root / '.env').read_text()
db_path = root / 'data/evershelf.db'
assert db_path.is_file()


def req(action, body=None):
    url = args.base_url.rstrip('/') + '/api/index.php?action=' + action
    payload = None if body is None else json.dumps(body).encode('utf-8')
    request = urllib.request.Request(url, data=payload, headers={
        'Content-Type': 'application/json', 'X-EverShelf-Request': '1'})
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.status, json.load(response)
    except urllib.error.HTTPError as ex:
        return ex.code, json.loads(ex.read())


def create(label):
    status, product = req('product_save', {'name': label + uuid.uuid4().hex[:10],
        'name_user_set': 1, 'unit': 'pz', 'category': 'conserve'})
    assert status == 200 and product['success'], (status, product)
    pid = product['id']
    status, added = req('inventory_add', {
        'product_id': pid, 'quantity': 6, 'unit': 'pz', 'location': 'dispensa'})
    assert status == 200 and added['success'], (status, added)
    return pid, added['inventory_id']


def recent_popular():
    status, data = req('recent_popular_products')
    assert status == 200, (status, data)
    return {int(row['product_id']): row for row in data['recent']}, {
        int(row['product_id']): row for row in data['popular']}, data


def assert_hidden(pid, description):
    recent, popular, data = recent_popular()
    assert pid not in recent, (description, data['recent'])
    assert pid not in popular, (description, data['popular'])
    assert pid not in data['recent_ids'], (description, data['recent_ids'])
    print('PASS excluded:', description)


# Exactly the observed case: Mac 16 -> iPhone chooses 14 in a
# version-checked stock conflict resolution, recording an 'out' correction.
pid, iid = create('QuickAccessOfflineCorrection-')
status, changed = req('inventory_update', {'id': iid, 'quantity': 16})
assert status == 200 and changed['success'], (status, changed)
with sqlite3.connect(db_path) as db:
    version = db.execute('SELECT revision FROM inventory WHERE id=?', (iid,)).fetchone()[0]
status, resolved = req('inventory_conflict_resolve', {
    'id': iid, 'product_id': pid, 'revision': version,
    'mode': 'replace', 'quantity': 14, '_offline_op_id': str(uuid.uuid4()),
})
assert status == 200 and resolved['success'] and resolved['quantity'] == 14, (status, resolved)
with sqlite3.connect(db_path) as db:
    note = db.execute("SELECT notes FROM transactions WHERE product_id=? AND type='out' ORDER BY id DESC LIMIT 1", (pid,)).fetchone()
    assert note == ('[Manual correction]',), note
assert_hidden(pid, 'iPhone conflict resolution 16 to 14')


# Location transfers and deletion are not consumption.
pid_move, iid_move = create('QuickAccessMoved-')
status, moved = req('inventory_update', {'id': iid_move, 'location': 'frigo'})
assert status == 200 and moved['success'], (status, moved)
assert_hidden(pid_move, 'location move without consumption')

pid_delete, iid_delete = create('QuickAccessDeleted-')
status, deleted = req('inventory_delete', {'id': iid_delete})
assert status == 200 and deleted['success'], (status, deleted)
assert_hidden(pid_delete, 'inventory deletion')


# Ledger reconciliation/housekeeping and undos must not count as consumption.
pid_repair, iid_repair = create('QuickAccessReconciled-')
with sqlite3.connect(db_path) as db:
    db.execute("""INSERT INTO transactions (product_id,type,quantity,location,notes)
        VALUES (?,'out',2,'dispensa','[Reconciliation] Stock accepted as-is (ledger aligned)')""", (pid_repair,))
    db.execute("""INSERT INTO transactions (product_id,type,quantity,location,notes)
        VALUES (?,'out',1,'dispensa','[Finished] Trace amount cleared')""", (pid_repair,))
assert_hidden(pid_repair, 'housekeeping/reconciliation entries')

pid_undone, iid_undone = create('QuickAccessUndone-')
with sqlite3.connect(db_path) as db:
    db.execute("""INSERT INTO transactions (product_id,type,quantity,location,notes,undone)
        VALUES (?,'out',1,'dispensa','',1)""", (pid_undone,))
assert_hidden(pid_undone, 'undone consumption')


# Actual 'use' must still appear and count correctly in BOTH widgets.
pid_used, iid_used = create('QuickAccessActuallyUsed-')
status, used = req('inventory_use', {
    'product_id': pid_used, 'quantity': 1, 'location': 'dispensa',
    'notes': 'Echte Verwendung', 'use_all': False})
assert status == 200 and used['success'], (status, used)
recent, popular, _ = recent_popular()
assert pid_used in recent, 'Real use is absent from Recently used'
assert pid_used in popular and int(popular[pid_used]['usage_count']) == 1, popular
print('PASS actual use: listed in Recently used and Most used')

# A subsequent manual correction should not raise the actual-use count.
status, changed = req('inventory_update', {'id': iid_used, 'quantity': 3})
assert status == 200 and changed['success'], (status, changed)
recent, popular, _ = recent_popular()
assert pid_used in recent
assert int(popular[pid_used]['usage_count']) == 1, popular[pid_used]
print('PASS manual adjustment after real use: does not inflate usage_count')

with sqlite3.connect(db_path) as db:
    assert db.execute('PRAGMA quick_check').fetchone()[0] == 'ok'
print('All Recently used and Most used actual-consumption tests passed.')
