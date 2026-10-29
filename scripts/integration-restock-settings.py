#!/usr/bin/env python3
"""Check plan/trip restock options against real HTTP/SQLite, with test-only config.

Refuses non-loopback and unmarked hosts. Restores the sandbox .env and smart
cache after running. Never run against a household or shared installation.
"""
import argparse
import json
import secrets
import sqlite3
import string
import sys
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


def verify(ok, label):
    if not ok:
        raise AssertionError(label)
    print("PASS:", label)


def call(base, action, body=None):
    req = Request(
        f"{base}/api/index.php?action={action}",
        data=json.dumps(body).encode() if body is not None else None,
        method="POST" if body is not None else "GET",
        headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"}
        if body is not None else {},
    )
    try:
        with urlopen(req, timeout=15) as res:
            return res.status, json.load(res)
    except HTTPError as error:
        try:
            return error.code, json.load(error)
        finally:
            error.close()


def run_case(root, base, mode):
    token = "".join(secrets.choice(string.ascii_lowercase) for _ in range(19))
    title = f"Auditvorrat{token} Sorte"

    status, product = call(base, "product_save", {
        "name": title, "name_user_set": 1, "lang": "de",
        "unit": "conf", "default_quantity": 690, "package_unit": "g",
        "category": "conserve",
    })
    verify(status == 200 and product.get("success") is True,
           f"{mode}: synthetic product created")
    product_id = int(product["id"])

    db = sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True)
    try:
        name, generic = db.execute(
            "SELECT name, shopping_name FROM products WHERE id=?", (product_id,)
        ).fetchone()
    finally:
        db.close()
    verify(generic != "", f"{mode}: shopping family available")

    status, added = call(base, "shopping_add", {
        "items": [{"name": generic, "rawName": name, "specification": "Test manuell"}]
    })
    verify(status == 200 and added.get("added", 0) >= 1,
           f"{mode}: user-entered shopping row added")

    snapshot = {
        "success": True,
        "cached_ts": 1793300000,
        "items": [{
            "name": name, "product_id": product_id, "shopping_name": generic,
            "unit": "conf", "default_qty": 690, "package_unit": "g",
            "current_qty": 0, "period_usage": 3,
            "suggested_qty": 3, "suggested_unit": "conf",
            "edible_days": 14, "urgency": "critical", "days_left": 0,
        }],
    }
    (root / "data/smart_shopping_cache.json").write_text(
        json.dumps(snapshot, ensure_ascii=False), encoding="utf-8"
    )

    status, purchased = call(base, "inventory_add", {
        "product_id": product_id, "quantity": 1,
        "location": "dispensa", "unit": "conf",
    })
    verify(status == 200 and purchased.get("success") is True,
           f"{mode}: a single package was persisted")
    verify(abs(float(purchased.get("total_qty", 0)) - 1.0) < 0.001,
           f"{mode}: quantity remained one pack, not 690 packs/grams")

    expect_keep = mode == "plan"
    verify(bool(purchased.get("shopping_kept")) is expect_keep,
           f"{mode}: shopping_kept agrees with chosen restock mode")
    verify(bool(purchased.get("removed_from_bring")) is not expect_keep,
           f"{mode}: removed_from_bring agrees with chosen restock mode")

    status, listed = call(base, "shopping_list")
    verify(status == 200 and listed.get("success") is True,
           f"{mode}: shopping list reload successful")
    matched = [
        x for x in listed["purchase"]
        if x.get("name", "").casefold() == generic.casefold()
        or x.get("rawName", "").casefold() == name.casefold()
    ]
    verify(bool(matched) is expect_keep,
           f"{mode}: partial restock {'keeps' if expect_keep else 'removes'} shopping row")

    db = sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True)
    try:
        qty = db.execute("SELECT SUM(quantity) FROM inventory WHERE product_id=?",
                         (product_id,)).fetchone()[0]
        verify(abs(float(qty) - 1.0) < 0.001,
               f"{mode}: independent SQLite read confirms quantity")
        verify(db.execute("PRAGMA quick_check").fetchone()[0] == "ok",
               f"{mode}: SQLite integrity")
    finally:
        db.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", type=Path, required=True)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    parser.add_argument("--mode", choices=["plan", "trip", "both"], default="both")
    args = parser.parse_args()
    parsed = urlparse(args.base_url)
    if parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "localhost", "::1"}:
        parser.error("Refusing non-loopback test server")

    root = args.sandbox_root.resolve(strict=True)
    env_path = root / ".env"
    if not env_path.exists() or "EVERSHELF_SANDBOX=1" not in env_path.read_text():
        parser.error("Refusing unmarked test data")
    if "SHOPPING_MODE=internal" not in env_path.read_text():
        parser.error("This test only supports internal shopping mode")
    if not (root / "data/evershelf.db").exists():
        parser.error("Missing disposable database")

    previous_env = env_path.read_bytes()
    cache = root / "data/smart_shopping_cache.json"
    previous_cache = cache.read_bytes() if cache.exists() else None
    try:
        modes = ("plan", "trip") if args.mode == "both" else (args.mode,)
        for mode in modes:
            env_path.write_bytes(previous_env + f"\nSHOPPING_REMOVE_ON_BUY={mode}\n".encode())
            run_case(root, args.base_url.rstrip("/"), mode)
        print("All plan/trip partial-restock configuration tests passed.")
    finally:
        env_path.write_bytes(previous_env)
        if previous_cache is None:
            cache.unlink(missing_ok=True)
        else:
            cache.write_bytes(previous_cache)


if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, AssertionError, json.JSONDecodeError) as error:
        print("FAIL:", error, file=sys.stderr)
        sys.exit(1)
