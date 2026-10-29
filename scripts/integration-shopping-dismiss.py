#!/usr/bin/env python3
"""Exercise real shopping_remove HTTP plus the cron/live-sync PHP functions.

Runs exclusively against a marked, loopback-only disposable EverShelf sandbox.
Usage: python3 scripts/integration-shopping-dismiss.py
         --sandbox-root /path/to/sandbox
         --base-url http://127.0.0.1:18080
"""
import argparse
import json
import secrets
import string
import sqlite3
import subprocess
import sys
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print("PASS:", message)


def api(base, action, body=None, query=""):
    url = f"{base}/api/index.php?action={action}{query}"
    req = Request(
        url,
        data=json.dumps(body).encode() if body is not None else None,
        headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"}
        if body is not None else {},
        method="POST" if body is not None else "GET",
    )
    try:
        with urlopen(req, timeout=15) as response:
            return response.status, json.load(response)
    except HTTPError as exc:
        try:
            return exc.code, json.load(exc)
        finally:
            exc.close()


def sync_php(root, expression):
    # Calls the same internal functions that the real scheduled cron and
    # inventory-change hooks use; no mock implementation or server mutation.
    php = (
        "define('CRON_MODE', true); "
        "require_once 'api/bootstrap.php'; "
        "require_once 'api/index.php'; "
        "$db=getDB(); "
        "echo json_encode(" + expression + ", JSON_UNESCAPED_UNICODE);"
    )
    run = subprocess.run(
        ["php", "-r", php],
        cwd=root,
        capture_output=True,
        text=True,
        timeout=25,
        check=True,
    )
    return json.loads(run.stdout.strip())


def list_matches(base, raw):
    status, result = api(base, "shopping_list")
    check(status == 200 and result.get("success") is True, "HTTP shopping_list available")
    return [
        row for row in result.get("purchase", [])
        if str(row.get("rawName", "")).casefold() == raw.casefold()
    ]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", required=True, type=Path)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    args = parser.parse_args()

    endpoint = urlparse(args.base_url)
    if endpoint.scheme != "http" or endpoint.hostname not in {"127.0.0.1", "localhost", "::1"}:
        parser.error("Refusing any non-loopback URL")
    root = args.sandbox_root.resolve(strict=True)
    env = root / ".env"
    if not env.is_file() or "EVERSHELF_SANDBOX=1" not in env.read_text():
        parser.error("Refusing to run outside a marked disposable sandbox")
    if not (root / "data/evershelf.db").is_file():
        parser.error("Sandbox database does not exist")
    if "SHOPPING_MODE=internal" not in env.read_text():
        parser.error("Requires internal shopping mode")

    base = args.base_url.rstrip("/")
    tag = "".join(secrets.choice(string.ascii_lowercase) for _ in range(18))
    name = f"Auditvorrat{tag} Sorte"

    status, saved = api(base, "product_save", {
        "name": name, "lang": "de", "name_user_set": 1,
        "category": "obst", "unit": "pz", "default_quantity": 1,
    })
    check(status == 200 and saved.get("success") is True, "Synthetic unstocked product created")
    pid = int(saved["id"])
    status, found = api(base, "product_get", query=f"&id={pid}")
    check(status == 200 and found.get("success") is True, "Product round-trips")
    product = found["product"]
    raw = product["name"]
    generic = product.get("shopping_name") or raw
    check(generic != "", "Server produced a usable shopping name")
    check(generic.casefold() != raw.casefold(),
          "Generic shopping title intentionally differs from specific rawName")

    cache_file = root / "data/smart_shopping_cache.json"
    original_cache = cache_file.read_bytes() if cache_file.is_file() else None

    # A stale, still-urgent forecast is what the periodic sync might see.
    snapshot = {
        "success": True,
        "cached_ts": 1793300000,
        "items": [{
            "name": raw,
            "shopping_name": generic,
            "product_id": pid,
            "unit": "pz",
            "default_quantity": 1,
            "package_unit": "pz",
            "urgency": "critical",
            "current_qty": 0,
            "period_usage": 3,
            "days_left": 0,
        }],
    }

    def put_snapshot():
        cache_file.write_text(json.dumps(snapshot, ensure_ascii=False), encoding="utf-8")

    try:
        put_snapshot()
        first = sync_php(root, "internalShoppingAutoAddCritical($db)")
        check(first.get("added", 0) >= 1, "Cron sync adds an urgently depleted product")

        rows = list_matches(base, raw)
        check(len(rows) == 1, "Urgent item appears on the list exactly once")
        item = rows[0]

        status, removed = api(base, "shopping_remove", {
            "name": item["name"],
            "rawName": item.get("rawName", ""),
            "purchased": False,
        })
        check(status == 200 and removed.get("removed", 0) >= 1,
              "HTTP remove acknowledges a real deletion")
        check(len(list_matches(base, raw)) == 0, "Row disappears on independent HTTP read")

        # shopping_remove invalidates the cache. Put the *same stale* forecast
        # back: this is a stronger test than relying on a fresh empty forecast.
        put_snapshot()
        second = sync_php(root, "internalShoppingAutoAddCritical($db)")
        check(second.get("added", 0) == 0, "Cron sync respects removal despite stale forecast")
        check(len(list_matches(base, raw)) == 0, "Item stays removed after cron sync")

        live = sync_php(root, f"bringQuickSyncProduct($db, {pid})")
        check(len(list_matches(base, raw)) == 0, "Real-time sync does not resurrect removed item")

        third = sync_php(root, "internalShoppingAutoAddCritical($db)")
        check(third.get("added", 0) == 0, "Repeated cron sync remains idempotent")
        check(len(list_matches(base, raw)) == 0, "Repeated sync keeps the list empty")

        db = sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True)
        try:
            check(db.execute("PRAGMA quick_check").fetchone()[0] == "ok",
                  "SQLite is consistent after multiple sync attempts")
            stored = db.execute(
                "SELECT value FROM app_settings WHERE key='shopping_dismissed'"
            ).fetchone()
            check(stored is not None and isinstance(json.loads(stored[0]), dict),
                  "The removal decision remains stored in app_settings")
        finally:
            db.close()
        print("All HTTP/cron/real-time shopping dismiss tests passed.")
    finally:
        if original_cache is None:
            cache_file.unlink(missing_ok=True)
        else:
            cache_file.write_bytes(original_cache)


if __name__ == "__main__":
    try:
        main()
    except (OSError, AssertionError, ValueError, subprocess.CalledProcessError) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        sys.exit(1)
