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
    conn = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
    try:
        qty = conn.execute(
            "SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id=?", (pid,)
        ).fetchone()[0]
        check(abs(qty - 2) < 1e-9, "separate SQLite connection confirms inventory quantity")
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
