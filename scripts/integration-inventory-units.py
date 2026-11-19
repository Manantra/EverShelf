#!/usr/bin/env python3
"""Issue #28 real HTTP/SQLite test; refuses unmarked or non-loopback instances."""
import argparse
import json
import sqlite3
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen

ap = argparse.ArgumentParser()
ap.add_argument('--sandbox-root', type=Path, required=True)
ap.add_argument('--base-url', required=True)
args = ap.parse_args()
root = args.sandbox_root.resolve(strict=True)
url = urlparse(args.base_url)
assert url.scheme == 'http' and url.hostname in ('localhost', '127.0.0.1', '::1')
assert (root / '.env').is_file() and 'EVERSHELF_SANDBOX=1' in (root / '.env').read_text()
dbfile = root / 'data/evershelf.db'

def req(action, body):
    request = Request(args.base_url.rstrip('/') + '/api/index.php?action=' + action,
        data=json.dumps(body).encode(), method='POST',
        headers={'Content-Type': 'application/json', 'X-EverShelf-Request': '1'})
    try:
        with urlopen(request, timeout=25) as resp:
            return resp.status, json.load(resp)
    except HTTPError as err:
        return err.code, json.loads(err.read())

def state(pid):
    with sqlite3.connect(dbfile) as conn:
        unit, pack, pack_unit = conn.execute('SELECT unit, default_quantity, package_unit FROM products WHERE id=?', (pid,)).fetchone()
        stock = conn.execute('SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id=?', (pid,)).fetchone()[0]
        ledger = conn.execute("SELECT COALESCE(SUM(quantity),0) FROM transactions WHERE product_id=? AND type='in'", (pid,)).fetchone()[0]
        assert conn.execute('PRAGMA quick_check').fetchone()[0] == 'ok'
        return unit, pack, pack_unit, stock, ledger

name = 'Unit Audit ' + uuid.uuid4().hex[:10]
code, r = req('product_save', {'name':name, 'name_user_set':1, 'category':'conserve', 'unit':'g',
                                 'default_quantity':180, 'package_unit':'g'})
assert code == 200 and r.get('success'), (code,r)
pid = r['id']
code,r = req('inventory_add', {'product_id':pid, 'quantity':2, 'unit':'conf', 'location':'frigo'})
assert code == 200 and r.get('success'), (code,r)
assert state(pid) == ('g', 180, 'g', 360, 360), state(pid)
print('PASS: 2 packs x 180g is stored as 360g, visible and ledger-consistent')

code,r = req('product_save', {'id':pid,'name':name,'name_user_set':1,
                                  'category':'conserve','unit':'pz',
                                  'default_quantity':180,'package_unit':'g'})
assert code == 200 and r.get('success') and r.get('unit_kept') is True, (code,r)
assert state(pid) == ('g',180,'g',360,360), state(pid)
print('PASS: product_save cannot reinterpret live gram stock as pieces')

code,r = req('inventory_add', {'product_id':pid, 'quantity':2, 'unit':'ml', 'location':'frigo'})
assert code == 409 and r.get('error_key') == 'error.unit_mismatch', (code,r)
assert state(pid) == ('g',180,'g',360,360), state(pid)
print('PASS: incompatible unit fails closed and preserves stock + ledger')

code,r = req('inventory_add', {'product_id':pid, 'quantity':0.5, 'unit':'kg','location':'dispensa'})
assert code == 200 and r.get('success'), (code,r)
assert state(pid) == ('g',180,'g',860,860), state(pid)
print('PASS: 0.5kg becomes 500g and retains existing rows at different locations')

code,r = req('inventory_add', {'product_id':pid, 'quantity':1, 'unit':'conf', 'location':'frigo',
                                   '_offline_op_id':uuid.uuid4().hex})
assert code == 200 and r.get('success'), (code,r)
assert state(pid) == ('g',180,'g',1040,1040), state(pid)
print('PASS: offline-receipt addition retains converted quantity semantics')

# A stale add-form package size must not corrupt known pack metadata.
code,r = req('inventory_add', {'product_id':pid, 'quantity':1, 'unit':'conf',
    'package_size':1, 'package_unit':'g', 'location':'frigo'})
assert code == 200 and r.get('success'), (code,r)
assert state(pid) == ('g',180,'g',1220,1220), state(pid)
print('PASS: stale incoming package size cannot overwrite existing metadata')

# CSV uses the same stored-product unit and must leave failed rows unchanged.
def csv_row(unit, quantity):
    return {'name':name,'category':'conserve','location':'frigo',
            'quantity':quantity,'unit':unit}
code,r = req('import_inventory', {'mode':'commit','confirm':True,
                                  'rows':[csv_row('conf',1)]})
assert code == 200 and r.get('imported') == 1 and r.get('failed') == 0, (code,r)
assert state(pid) == ('g',180,'g',1400,1400), state(pid)
print('PASS: CSV pack import converts using existing product package metadata')

code,r = req('import_inventory', {'mode':'commit','confirm':True,
                                  'rows':[csv_row('ml',5)]})
assert r.get('success') is False or r.get('failed',0) >= 1, (code,r)
assert state(pid) == ('g',180,'g',1400,1400), state(pid)
print('PASS: incompatible CSV import rolls back row, keeps product and stock')

print('PASS: all unit integrity HTTP/SQLite assertions')
