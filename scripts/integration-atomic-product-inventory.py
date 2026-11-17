#!/usr/bin/env python3
"""Atomic product title + inventory edit over actual HTTP/SQLite.

All writes target explicitly marked, loopback-only synthetic test database.
Exercises a forced stock-write failure *after* the product title write and
proves both rolled back, plus stale multi-device conflicting edits.
"""
import argparse
import concurrent.futures
import json
import sqlite3
import sys
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


def req(base, action, data=None, suffix=""):
    request = Request(
        base.rstrip("/") + "/api/index.php?action=" + action + suffix,
        data=json.dumps(data, ensure_ascii=False).encode() if data is not None else None,
        method="POST" if data is not None else "GET",
        headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"}
        if data is not None else {},
    )
    try:
        with urlopen(request, timeout=30) as response:
            return response.status, json.load(response)
    except HTTPError as exc:
        try:
            return exc.code, json.load(exc)
        finally:
            exc.close()


def check(condition, description):
    if not condition:
        raise AssertionError(description)
    print("PASS:", description)


def record(root, product_id, inventory_id):
    with sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True) as db:
        product = db.execute(
            "SELECT name, notes, unit, default_quantity FROM products WHERE id=?", (product_id,)
        ).fetchone()
        stock = db.execute(
            "SELECT quantity,location FROM inventory WHERE id=?", (inventory_id,)
        ).fetchone()
        ledger = db.execute(
            "SELECT type,quantity,notes FROM transactions WHERE product_id=? ORDER BY id",
            (product_id,),
        ).fetchall()
        return (product, stock, ledger)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", required=True, type=Path)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    parser.add_argument("--second-url", default="http://127.0.0.1:18081")
    args = parser.parse_args()
    for url in [args.base_url, args.second_url]:
        parsed = urlparse(url)
        if parsed.scheme != "http" or parsed.hostname not in ("localhost", "127.0.0.1", "::1"):
            parser.error("Refusing non-loopback test endpoint")
    root = args.sandbox_root.resolve(strict=True)
    marker = root / ".env"
    if not marker.is_file() or "EVERSHELF_SANDBOX=1" not in marker.read_text():
        parser.error("Refusing unmarked / production database")
    db = root / "data/evershelf.db"
    if not db.is_file():
        parser.error("Missing isolated test DB")

    base, other = args.base_url, args.second_url
    original = "Atomic Audit " + uuid.uuid4().hex[:12]
    status, created = req(base, "product_save", {
        "name": original, "name_user_set": 1, "brand": "Audit Brand",
        "notes": "Do not erase", "category": "conserve",
        "unit": "conf", "default_quantity": 690, "package_unit": "g",
    })
    check(status == 200 and created.get("success") is True, "Create isolated audited product")
    pid = int(created["id"])
    status, created_stock = req(base, "inventory_add", {
        "product_id": pid, "quantity": 2, "unit": "conf", "location": "dispensa",
    })
    check(status == 200 and created_stock.get("success") is True, "Create stock row")
    with sqlite3.connect(db) as con:
        iid = con.execute(
            "SELECT id FROM inventory WHERE product_id=? ORDER BY id DESC LIMIT 1", (pid,)
        ).fetchone()[0]
    initial = record(root, pid, iid)
    check(initial[0][0] == original and initial[0][1] == "Do not erase" and initial[1][0] == 2,
          "Initial product notes and stock are correct")

    new_name = original + " Neu"
    update = {
        "id": iid, "product_id": pid, "quantity": 4, "location": "frigo",
        "unit": "conf",
        "expected_inventory": {"quantity": 2, "location": "dispensa"},
        "product_edit": {
            "name": new_name, "expected_name": original,
            "expected_fields": {"brand": "Audit Brand", "notes": "Do not erase"},
        },
    }
    status, done = req(base, "inventory_update", update)
    check(status == 200 and done.get("success") is True and done.get("product_name") == new_name,
          "One HTTP operation saves product rename and stock changes")
    now = record(root, pid, iid)
    check(now[0][0] == new_name and now[0][1] == "Do not erase"
          and now[1] == (4, "frigo"), "Both changes persisted without clearing product notes")
    check(len(now[2]) > len(initial[2]), "Stock change has corresponding ledger entry")

    status, stale = req(other, "inventory_update", {
        **update, "quantity": 99,
        "product_edit": {**update["product_edit"], "name": original + " Stale"},
    })
    check(status == 409 and stale.get("success") is False,
          "Stale second editor receives conflict (not false success)")
    check(record(root, pid, iid) == now,
          "Stale product + stock request did not change either record or ledger")

    mismatch = {
        **update, "quantity": 20,
        "expected_inventory": {"quantity": 2, "location": "dispensa"},
        "product_edit": {
            "name": new_name + " Conflict",
            "expected_name": new_name,
            "expected_fields": {"notes": "Do not erase"},
        },
    }
    status, rejected = req(other, "inventory_update", mismatch)
    check(status == 409 and rejected.get("error") == "inventory_conflict",
          "Stock conflict blocks product rename in same transaction")
    check(record(root, pid, iid) == now,
          "Stock conflict leaves name, stock and ledger completely unchanged")

    mismatch = {
        **update, "quantity": 20,
        "expected_inventory": {"quantity": 4, "location": "frigo"},
        "product_edit": { "name": new_name + " Conflict", "expected_name": original },
    }
    status, rejected = req(other, "inventory_update", mismatch)
    check(status == 409 and rejected.get("error") == "product_conflict",
          "Product conflict blocks inventory changes in same transaction")
    check(record(root, pid, iid) == now,
          "Product conflict leaves name, stock and ledger completely unchanged")

    trigger_name = "atomic_audit_" + uuid.uuid4().hex[:10]
    with sqlite3.connect(db, timeout=20) as con:
        con.execute(f"""CREATE TRIGGER {trigger_name}
            BEFORE UPDATE ON inventory WHEN OLD.id={iid}
            BEGIN SELECT RAISE(ABORT, 'test-only inventory write failure'); END""")
    try:
        interrupted = {
            **update, "quantity": 5,
            "expected_inventory": {"quantity": 4, "location": "frigo"},
            "product_edit": {
                "name": new_name + " Interrupted", "expected_name": new_name,
            },
        }
        status, failed = req(base, "inventory_update", interrupted)
        check(status >= 400 and failed.get("success") is False,
              "Injected inventory SQL failure is reported as failure")
        check(record(root, pid, iid) == now,
              "Failing second write rolls back earlier product rename and inventory ledger")
    finally:
        with sqlite3.connect(db, timeout=20) as con:
            con.execute(f"DROP TRIGGER IF EXISTS {trigger_name}")

    status, invalid = req(base, "inventory_update", {
        "id": iid, "quantity": 88,
        "product_edit": {"name": new_name + " Invalid", "expected_name": new_name},
    })
    check(status == 400 and invalid.get("error") == "invalid_product_edit",
          "Combined edit cannot skip inventory conflict protection")
    check(record(root, pid, iid) == now,
          "Rejected unguarded combined edit leaves stock and product unchanged")

    status, wrong_target = req(base, "inventory_update", {
        "id": iid, "product_id": pid + 999, "quantity": 99,
        "expected_inventory": {"quantity": 4, "location": "frigo"},
        "product_edit": {"name": new_name + " Wrong", "expected_name": new_name},
    })
    check(status == 409 and wrong_target.get("success") is False,
          "A combined edit cannot redirect the product ID to another row")
    check(record(root, pid, iid) == now,
          "Rejecting a mismatched product ID leaves both records unchanged")

    status, legacy = req(base, "inventory_update", {
        "id": iid, "quantity": 5, "location": "frigo",
    })
    check(status == 200 and legacy.get("success") is True,
          "Legacy inventory client still works without opt-in combined edit")
    after = record(root, pid, iid)
    check(after[0][0] == new_name and after[1][0] == 5,
          "Legacy quantity edit never modifies product title")

    # Two devices submit incompatible combined edits from the same snapshot.
    # Exactly one may win the inventory/product transaction.
    before_race = record(root, pid, iid)
    def contender(index):
        return req((base, other)[index], "inventory_update", {
            "id": iid, "product_id": pid, "quantity": 6 + index,
            "location": "frigo",
            "expected_inventory": {"quantity": 5, "location": "frigo"},
            "product_edit": {
                "name": new_name + (" First" if index == 0 else " Second"),
                "expected_name": new_name,
                "expected_fields": {"notes": "Do not erase"},
            },
        })
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as workers:
        results = list(workers.map(contender, range(2)))
    successes = [(code, obj) for code, obj in results
                 if code == 200 and obj.get("success") is True]
    failures = [(code, obj) for code, obj in results
                if (code == 409 and obj.get("error") in ("inventory_conflict", "product_conflict"))
                or (code == 503 and obj.get("error") == "database_busy")]
    check(len(successes) == 1 and len(failures) == 1,
          "Two competing combined edits yield exactly one commit and one clean rejection")
    after_race = record(root, pid, iid)
    check((after_race[0][0], after_race[1][0]) in {
        (new_name + " First", 6), (new_name + " Second", 7)
    }, "Winning product name and inventory quantity belong to the same editor")
    check(len(after_race[2]) == len(before_race[2]) + 1,
          "Exactly one additional correction entered the transaction ledger")

    with sqlite3.connect(f"file:{db}?mode=ro", uri=True) as con:
        check(con.execute("PRAGMA quick_check").fetchone()[0] == "ok",
              "SQLite integrity remains OK")
    print("All atomic combined product/inventory edit tests passed.")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, ValueError, OSError, json.JSONDecodeError) as exc:
        print("FAIL:", exc, file=sys.stderr)
        sys.exit(1)
