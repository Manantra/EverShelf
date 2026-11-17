#!/usr/bin/env python3
"""Isolated EverShelf HTTP/SQLite persistence smoke test.

Requires a loopback-only test instance and an EVERSHELF_SANDBOX=1 marker in
its .env. No household credentials or production database may be used.

Usage:
    python3 scripts/test-http-persistence.py \
      --sandbox-root /path/to/EverShelf-stability-sandbox \
      --base-url http://127.0.0.1:18080
"""
from __future__ import annotations

import argparse
import json
import sqlite3
import sys
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


def request(base: str, action: str, payload: dict | None = None, query: str = ""):
    url = f"{base}/api/index.php?action={action}{query}"
    headers = {}
    if payload is not None:
        headers = {
            "Content-Type": "application/json",
            "X-EverShelf-Request": "1",
        }
    req = Request(
        url,
        data=json.dumps(payload, ensure_ascii=False).encode() if payload is not None else None,
        headers=headers,
        method="POST" if payload is not None else "GET",
    )
    try:
        with urlopen(req, timeout=15) as resp:
            return resp.status, json.load(resp)
    except HTTPError as exc:
        try:
            return exc.code, json.load(exc)
        finally:
            exc.close()


def check(cond: bool, message: str):
    if not cond:
        raise AssertionError(message)
    print("PASS:", message)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", required=True, type=Path)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    args = parser.parse_args()
    parsed = urlparse(args.base_url)
    if parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "::1", "localhost"}:
        parser.error("Refusing non-loopback target")
    root = args.sandbox_root.resolve(strict=True)
    marker = root / ".env"
    if "EVERSHELF_SANDBOX=1" not in marker.read_text():
        parser.error("Missing EVERSHELF_SANDBOX=1 marker: refusing to write to instance")
    db_path = root / "data" / "evershelf.db"
    if not db_path.is_file():
        parser.error(f"Missing test database: {db_path}")

    base = args.base_url.rstrip("/")
    tag = uuid.uuid4().hex[:10]
    initial = f"Passierte tomaten audit {tag}"
    renamed = f"Passierte Tomaten audit {tag}"

    status, ping = request(base, "ping")
    check(status == 200 and ping.get("ok") is True, "HTTP ping")

    body = {
        "name": initial, "name_user_set": 1, "lang": "de",
        "category": "conserve", "unit": "conf",
        "default_quantity": 1, "package_unit": "conf",
    }
    status, saved = request(base, "product_save", body)
    check(status == 200 and saved.get("success") is True and int(saved.get("id", 0)) > 0,
          "HTTP creates new product")
    pid = int(saved["id"])

    body.update({"id": pid, "name": renamed})
    status, changed = request(base, "product_save", body)
    check(status == 200 and changed.get("success") is True, "HTTP accepts case-only title update")

    status, found = request(base, "product_get", query=f"&id={pid}")
    check(status == 200 and found.get("product", {}).get("name") == renamed,
          "fresh HTTP read returns exact user capitalization")

    # Two browser tabs both loaded the same version before any writes.
    # The second must not silently overwrite the first tab's manual rename.
    first_editor = renamed + " Alpha"
    second_editor = renamed + " Beta"
    status, first = request(base, "product_save", {
        **body, "name": first_editor, "expected_name": renamed,
    })
    check(status == 200 and first.get("success") is True,
          "first manual editor saves against its original title")
    status, stale = request(base, "product_save", {
        **body, "name": second_editor, "expected_name": renamed,
        "default_quantity": 999,
    })
    check(status == 409 and stale.get("error") == "product_conflict"
          and stale.get("success") is False,
          "second stale editor receives an explicit 409 conflict")
    status, latest = request(base, "product_get", query=f"&id={pid}")
    check(status == 200 and latest.get("product", {}).get("name") == first_editor
          and float(latest["product"]["default_quantity"]) == 1.0,
          "rejected rename cannot overwrite title or unrelated package settings")
    status, resolved = request(base, "product_save", {
        **body, "name": second_editor, "expected_name": first_editor,
    })
    check(status == 200 and resolved.get("success") is True,
          "editor can save after explicitly refreshing its expected title")
    renamed = second_editor

    # A stale editor can overwrite brand/notes even when the title is unchanged.
    status, first_fields = request(base, "product_save", {
        **body, "id": pid, "name": renamed, "brand": "Brand One",
        "notes": "First note", "expected_fields": {"brand": "", "notes": ""},
    })
    check(status == 200 and first_fields.get("success") is True,
          "first notes/brand editor saves against matching field snapshot")
    status, stale_fields = request(base, "product_save", {
        **body, "id": pid, "name": renamed, "brand": "Brand Two",
        "notes": "Stale note", "expected_fields": {"brand": "", "notes": ""},
    })
    check(status == 409 and stale_fields.get("error") == "product_conflict",
          "stale brand/notes edit is rejected even without a title change")
    status, field_row = request(base, "product_get", query=f"&id={pid}")
    check(status == 200 and field_row["product"]["brand"] == "Brand One"
          and field_row["product"]["notes"] == "First note",
          "rejected stale field edit leaves brand and notes unchanged")
    status, fresh_fields = request(base, "product_save", {
        **body, "id": pid, "name": renamed, "brand": "Brand Two",
        "notes": "Second note",
        "expected_fields": {"brand": "Brand One", "notes": "First note"},
    })
    check(status == 200 and fresh_fields.get("success") is True,
          "brand and notes save after refreshing the expected field snapshot")
    status, invalid_fields = request(base, "product_save", {
        **body, "id": pid, "name": renamed, "expected_fields": {"DROP TABLE": "x"},
    })
    check(status == 400 and invalid_fields.get("success") is False,
          "unknown expected-fields key cannot enter SQL")

    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        row = conn.execute(
            "SELECT name, name_user_set FROM products WHERE id = ?", (pid,)
        ).fetchone()
        check(row == (renamed, 1), "separate SQLite connection confirms persisted casing")
    finally:
        conn.close()

    missing_id = 999_999_999
    status, missing = request(base, "product_save", {**body, "id": missing_id})
    check(status == 404 and missing.get("success") is False,
          "updating a missing product returns an error, never false success")

    generic = f"Quinoa audit {tag}"
    status, added = request(base, "shopping_add", {"items": [
        {"name": generic, "rawName": f"Quinoa audit {tag} roh", "specification": "24 conf (manuell)"}
    ]})
    check(status == 200 and added.get("success") is True and added.get("added", 0) >= 1,
          "HTTP adds shopping-list row")

    status, listed = request(base, "shopping_list")
    check(status == 200 and listed.get("success") is True, "HTTP reads shopping list")
    matches = [r for r in listed.get("purchase", []) if tag.lower() in
               (str(r.get("name", "")) + " " + str(r.get("rawName", ""))).lower()]
    check(bool(matches), "new shopping row survives independent reload")
    item = matches[0]
    check(item.get("specification") == "24 conf (manuell)",
          "manually chosen 24-pack note is not clamped by automatic shopping guard")

    status, removed = request(base, "shopping_remove", {
        "name": item["name"], "rawName": item.get("rawName", ""), "purchased": False
    })
    check(status == 200 and removed.get("removed", 0) > 0,
          "HTTP deletion confirms at least one affected row")

    status, listed = request(base, "shopping_list")
    check(status == 200 and not any(
        tag.lower() in (str(r.get("name", "")) + " " + str(r.get("rawName", ""))).lower()
        for r in listed.get("purchase", [])
    ), "shopping row stays deleted on fresh HTTP read")

    status, no_op = request(base, "shopping_remove", {
        "name": item["name"], "rawName": item.get("rawName", ""), "purchased": False
    })
    check(status == 200 and no_op.get("removed") == 0,
          "repeat deletion is reported as zero affected rows")

    status, stock = request(base, "inventory_add", {
        "product_id": pid, "quantity": 2, "location": "dispensa", "unit": "conf",
    })
    check(status == 200 and stock.get("success") is True, "HTTP adds two packages to inventory")
    status, pantry = request(base, "inventory_list")
    matched_pantry = [row for row in pantry.get("inventory", []) if row.get("product_id") == pid]
    check(status == 200 and len(matched_pantry) == 1
          and matched_pantry[0].get("notes") == "Second note",
          "inventory editor receives current product notes rather than clearing them on rename")
    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        qty = conn.execute(
            "SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id=?", (pid,)
        ).fetchone()[0]
        check(abs(qty - 2) < 1e-9, "separate SQLite connection confirms inventory quantity")
    finally:
        conn.close()

    # Simulate two inventory screens loaded when this row held two packages.
    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        inv_id = conn.execute(
            "SELECT id FROM inventory WHERE product_id=? ORDER BY id DESC LIMIT 1", (pid,)
        ).fetchone()[0]
    finally:
        conn.close()
    expected = {"quantity": 2, "location": "dispensa"}
    status, inv_first = request(base, "inventory_update", {
        "id": inv_id, "quantity": 3, "expected_inventory": expected,
    })
    check(status == 200 and inv_first.get("success") is True,
          "first quantity editor successfully saves with its snapshot")
    status, inv_stale = request(base, "inventory_update", {
        "id": inv_id, "quantity": 5, "expected_inventory": expected,
    })
    check(status == 409 and inv_stale.get("error") == "inventory_conflict",
          "second stale quantity editor is rejected")
    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        current_qty = conn.execute("SELECT quantity FROM inventory WHERE id=?", (inv_id,)).fetchone()[0]
        check(current_qty == 3, "failed inventory edit did not overwrite committed quantity")
    finally:
        conn.close()
    status, inv_fresh = request(base, "inventory_update", {
        "id": inv_id, "quantity": 4,
        "expected_inventory": {"quantity": 3, "location": "dispensa"},
    })
    check(status == 200 and inv_fresh.get("success") is True,
          "inventory editor can save after refreshing its snapshot")
    status, invalid_inv = request(base, "inventory_update", {
        "id": inv_id, "quantity": 99, "expected_inventory": {"__invalid__": "x"},
    })
    check(status == 400 and invalid_inv.get("success") is False,
          "unknown inventory snapshot keys are rejected without writes")

    status, move = request(base, "inventory_update", {
        "id": inv_id, "location": "frigo",
        "expected_inventory": {"quantity": 4, "location": "dispensa"},
    })
    check(status == 200 and move.get("success") is True,
          "first location editor saves its new location")
    status, stale_loc = request(base, "inventory_update", {
        "id": inv_id, "quantity": 12,
        "expected_inventory": {"quantity": 4, "location": "dispensa"},
    })
    check(status == 409 and stale_loc.get("error") == "inventory_conflict",
          "stale location prevents an unrelated quantity overwrite")
    status, dated = request(base, "inventory_update", {
        "id": inv_id, "expiry_date": "2026-12-15",
        "expected_inventory": {"expiry_date": None, "location": "frigo"},
    })
    check(status == 200 and dated.get("success") is True,
          "first expiry-date editor saves its date")
    status, stale_date = request(base, "inventory_update", {
        "id": inv_id, "expiry_date": "2027-01-01",
        "expected_inventory": {"expiry_date": None, "location": "frigo"},
    })
    check(status == 409 and stale_date.get("error") == "inventory_conflict",
          "stale expiry date cannot overwrite an existing manual date")
    status, old_client = request(base, "inventory_update", {
        "id": inv_id, "quantity": 5,
    })
    check(status == 200 and old_client.get("success") is True,
          "legacy inventory client without snapshot retains compatibility")
    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        location, expiry, qty = conn.execute(
            "SELECT location, expiry_date, quantity FROM inventory WHERE id=?",
            (inv_id,)
        ).fetchone()
        check((location, expiry, qty) == ("frigo", "2026-12-15", 5),
              "legacy quantity edit does not alter location or expiry date")
    finally:
        conn.close()

    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        check(conn.execute("PRAGMA quick_check").fetchone()[0] == "ok",
              "SQLite integrity check")
    finally:
        conn.close()

    print("All isolated HTTP/SQLite persistence tests passed.")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, ValueError, json.JSONDecodeError) as err:
        print(f"FAIL: {err}", file=sys.stderr)
        sys.exit(1)
