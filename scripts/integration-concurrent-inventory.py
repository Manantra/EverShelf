#!/usr/bin/env python3
"""Test two independent HTTP workers sharing an isolated SQLite inventory.

Only accepts loopback URLs, a marked sandbox, and synthetic names. Run with two
PHP built-in servers rooted at the *same disposable fork* checkout.
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


def post(base, action, body):
    request = Request(
        base.rstrip("/") + "/api/index.php?action=" + action,
        data=json.dumps(body).encode(),
        method="POST",
        headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"},
    )
    try:
        with urlopen(request, timeout=35) as r:
            return r.status, json.load(r)
    except HTTPError as e:
        try:
            return e.code, json.load(e)
        finally:
            e.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", required=True, type=Path)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    parser.add_argument("--second-url", default="http://127.0.0.1:18081")
    parser.add_argument("--rounds", type=int, default=3)
    parser.add_argument("--parallel", type=int, default=12)
    args = parser.parse_args()
    for url in (args.base_url, args.second_url):
        parsed = urlparse(url)
        if parsed.scheme != "http" or parsed.hostname not in {"127.0.0.1", "::1", "localhost"}:
            parser.error("Refusing non-loopback server")
    root = args.sandbox_root.resolve(strict=True)
    env = root / ".env"
    if not env.is_file() or "EVERSHELF_SANDBOX=1" not in env.read_text():
        parser.error("Refusing unmarked/production environment")
    db_path = root / "data/evershelf.db"
    if not db_path.exists():
        parser.error("Missing isolated SQLite DB")

    # Clear only disposable rate-limit fixtures so repeated test executions do
    # not hit the application's intentional 120-requests/minute UI guard.
    # Never disable rate limiting in application code or a real installation.
    rate_dir = root / "data/rate_limits"
    if rate_dir.is_dir():
        for item in rate_dir.glob("*.json"):
            item.unlink()
    label = "Concurrency Audit " + uuid.uuid4().hex[:12]
    code, created = post(args.base_url, "product_save", {
        "name": label, "lang": "de", "name_user_set": 1,
        "unit": "conf", "default_quantity": 690, "package_unit": "g",
        "category": "conserve",
    })
    assert code == 200 and created.get("success") is True, (code, created)
    product_id = int(created["id"])
    total_ok = 0
    total_retried = 0
    for round_id in range(args.rounds):
        def add_one(i):
            url = args.base_url if i % 2 == 0 else args.second_url
            return post(url, "inventory_add", {
                "product_id": product_id, "quantity": 1, "unit": "conf",
                "location": "dispensa",
            })
        with concurrent.futures.ThreadPoolExecutor(max_workers=args.parallel) as threads:
            results = list(threads.map(add_one, range(args.parallel)))
        accepted = sum(1 for status, obj in results if status == 200 and obj.get("success") is True)
        errors = [(status, obj.get("error", "?")) for status, obj in results
                  if status != 200 or obj.get("success") is not True]
        total_ok += accepted
        # A 503 with database_busy is an acceptable *rejected* write only when
        # neither inventory nor ledger changed. Do not retry it inside this test.
        retryable = sum(1 for status, obj in results if status == 503 and obj.get('error') == 'database_busy')
        assert retryable == len(errors), f"Unexpected failures (including rate limits): {errors}"
        total_retried += retryable
        db = sqlite3.connect(f"file:{db_path}?mode=ro", uri=True)
        try:
            stored = db.execute("SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id=?",
                                (product_id,)).fetchone()[0]
            ledger = db.execute("SELECT COALESCE(SUM(quantity), 0) FROM transactions WHERE product_id=? AND type='in'",
                                (product_id,)).fetchone()[0]
            integrity = db.execute("PRAGMA quick_check").fetchone()[0]
        finally:
            db.close()
        print(f"round={round_id+1} accepted={accepted}/{args.parallel} "
              f"cumulative={total_ok} stored={stored:g} ledger={ledger:g} failures={errors[:3]}")
        assert integrity == "ok", "SQLite integrity violation"
        assert stored == total_ok, "Unacknowledged or missing concurrent inventory write"
        assert ledger == total_ok, "Unacknowledged or missing transaction ledger entry"
    print(f"All acknowledged writes consistent; {total_retried} safe rejected writes (503) had no side effects.")


if __name__ == "__main__":
    try:
        main()
    except (OSError, AssertionError, ValueError, json.JSONDecodeError) as e:
        print("FAIL:", e, file=sys.stderr)
        sys.exit(1)
