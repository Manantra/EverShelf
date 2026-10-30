#!/usr/bin/env python3
"""Field-specific product and inventory edit conflicts across two real PHP workers.

Uses synthetic data and an explicitly marked loopback sandbox only.
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


def api(base, action, payload=None):
    req = Request(
        base.rstrip("/") + "/api/index.php?action=" + action,
        data=json.dumps(payload).encode() if payload is not None else None,
        headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"}
        if payload is not None else {},
        method="POST" if payload is not None else "GET",
    )
    try:
        with urlopen(req, timeout=35) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        try:
            return error.code, json.load(error)
        finally:
            error.close()


def check_single_winner(results, kind):
    accepted = [(code, value) for code, value in results
                if code == 200 and value.get("success") is True]
    conflicts = [(code, value) for code, value in results
                 if code == 409 and value.get("success") is False
                 and value.get("error") == kind]
    busy = [(code, value) for code, value in results
            if code == 503 and value.get("success") is False
            and value.get("error") == "database_busy"]
    # SQLite may reject a contender before obtaining BEGIN IMMEDIATE. This is
    # a valid *zero-side-effect* rejection, not a lost acknowledgement. The
    # following direct SQL/ledger checks detect even one partial write.
    assert len(accepted) == 1 and len(conflicts) + len(busy) == len(results) - 1, (
        kind, len(accepted), len(conflicts), len(busy), results
    )


def scenario(root, urls, round_id):
    name = "Multi Field Audit " + uuid.uuid4().hex[:12]
    body = {
        "name": name, "name_user_set": 1,
        "unit": "conf", "default_quantity": 690, "package_unit": "g",
        "category": "conserve", "brand": "", "notes": "", "lang": "de",
    }
    code, created = api(urls[0], "product_save", body)
    assert code == 200 and created.get("success") is True
    pid = int(created["id"])

    def change_notes(i):
        return api(urls[i % 2], "product_save", {
            **body, "id": pid, "notes": f"Note writer {i}",
            "expected_fields": {"notes": "", "brand": ""},
        })
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        notes = list(pool.map(change_notes, range(5)))
    check_single_winner(notes, "product_conflict")

    with sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True) as db:
        row = db.execute("SELECT notes,brand,name FROM products WHERE id=?", (pid,)).fetchone()
    assert row[0].startswith("Note writer") and row[1] == "" and row[2] == name, row

    code, stock = api(urls[0], "inventory_add", {
        "product_id": pid, "quantity": 2, "unit": "conf", "location": "dispensa",
    })
    assert code == 200 and stock.get("success") is True, stock
    with sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True) as db:
        inv_id, initial = db.execute(
            "SELECT id,quantity FROM inventory WHERE product_id=? ORDER BY id DESC LIMIT 1",
            (pid,),
        ).fetchone()
    assert initial == 2

    def change_qty(i):
        return api(urls[i % 2], "inventory_update", {
            "id": inv_id, "quantity": 3 + i,
            "expected_inventory": {"quantity": 2, "location": "dispensa"},
        })
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        quantities = list(pool.map(change_qty, range(5)))
    check_single_winner(quantities, "inventory_conflict")

    with sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True) as db:
        stored = db.execute("SELECT quantity FROM inventory WHERE id=?", (inv_id,)).fetchone()[0]
        manual = db.execute(
            "SELECT type,quantity FROM transactions WHERE product_id=? AND notes='[Manual correction]'",
            (pid,),
        ).fetchall()
        assert stored in {3,4,5,6,7}, stored
        assert len(manual) == 1 and manual[0] == ("in", stored - 2), manual
        assert db.execute("PRAGMA quick_check").fetchone()[0] == "ok"
    print(f"round={round_id} notes 1/5 saved, inventory 1/5 saved, ledger {stored - 2:g}, SQLite ok")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", required=True, type=Path)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    parser.add_argument("--second-url", default="http://127.0.0.1:18081")
    args = parser.parse_args()
    for url in (args.base_url, args.second_url):
        parsed = urlparse(url)
        if parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "::1", "localhost"}:
            parser.error("Refusing non-loopback HTTP endpoint")
    root = args.sandbox_root.resolve(strict=True)
    marker = root / ".env"
    if not marker.is_file() or "EVERSHELF_SANDBOX=1" not in marker.read_text():
        parser.error("Refusing unmarked or production database")
    if not (root / "data/evershelf.db").exists():
        parser.error("Missing isolated test database")
    # Clear only marked disposable fixtures. Earlier CI stress tests can
    # otherwise exhaust the intentionally enabled production rate limiter.
    rate_dir = root / "data/rate_limits"
    if rate_dir.is_dir():
        for item in rate_dir.glob("*.json"):
            item.unlink()
    for i in range(1, 4):
        scenario(root, (args.base_url, args.second_url), i)
    print("All two-worker field and quantity conflict tests passed.")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, ValueError, json.JSONDecodeError) as error:
        print("FAIL:", error, file=sys.stderr)
        sys.exit(1)
