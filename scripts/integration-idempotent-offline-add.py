#!/usr/bin/env python3
"""Real two-worker HTTP+SQLite idempotent offline inventory_add regression.

Refuses production addresses or databases, uses unique synthetic products.
"""
import argparse
import concurrent.futures
import json
import sqlite3
import sys
import time
import uuid
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import urlparse
from urllib.request import Request, urlopen


def api(url, action, payload):
    req = Request(url.rstrip("/") + "/api/index.php?action=" + action,
                  data=json.dumps(payload).encode(), method="POST",
                  headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"})
    try:
        with urlopen(req, timeout=35) as response:
            return response.status, json.load(response)
    except HTTPError as exc:
        try:
            return exc.code, json.load(exc)
        finally:
            exc.close()


def replay_if_busy(url, payload):
    # SQLite WAL can reject a racing writer with a definitive 503 database_busy.
    # This is a safe non-write, not an idempotency failure. Retry the SAME
    # operation ID with bounded backoff, as the offline client would. The
    # returned 200 receipts and single ledger/stock write are asserted below.
    for attempt in range(5):
        code, reply = api(url, "inventory_add", payload)
        if code != 503 or reply.get("error") != "database_busy":
            return code, reply
        time.sleep(0.12 * (2 ** attempt))
    return code, reply


def case(dbpath, urls, scenario):
    name = "Receipt Audit " + uuid.uuid4().hex[:16]
    code, product = api(urls[0], "product_save", {
        "name": name, "name_user_set": 1, "unit": "conf",
        "default_quantity": 690, "package_unit": "g", "category": "conserve",
    })
    assert code == 200 and product.get("success"), (code, product)
    pid = product["id"]
    key = uuid.uuid4().hex
    payload = {"product_id": pid, "quantity": 2, "unit": "conf",
               "location": "dispensa", "id": -3241, "_offline_op_id": key}
    if scenario == "concurrent":
        with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
            replies = list(pool.map(
                lambda i: replay_if_busy(urls[i % 2], payload), range(8)))
        assert len(replies) == 8
        assert all(status == 200 and result.get("success") for status, result in replies), replies
        assert sum(1 for _, result in replies if result.get("idempotent_replay")) == 7, replies
    elif scenario == "fail_after_stock":
        # The trigger fails the receipt INSERT, after inventory and ledger
        # updates were written, proving the whole transaction is rolled back.
        trigger = "audit_receipt_fail_" + uuid.uuid4().hex[:10]
        with sqlite3.connect(dbpath) as db:
            db.execute(f"""CREATE TRIGGER {trigger}
                BEFORE INSERT ON offline_write_receipts
                WHEN NEW.operation_id='{key}'
                BEGIN SELECT RAISE(ABORT, 'injected receipt write failure'); END""")
        try:
            error_status, error_reply = api(urls[0], "inventory_add", payload)
            assert error_status >= 400 and error_reply.get("success") is False
            with sqlite3.connect(dbpath) as db:
                assert db.execute("SELECT COUNT(*) FROM inventory WHERE product_id=?",
                                  (pid,)).fetchone()[0] == 0
                assert db.execute("SELECT COUNT(*) FROM transactions WHERE product_id=?",
                                  (pid,)).fetchone()[0] == 0
                assert db.execute("SELECT COUNT(*) FROM offline_write_receipts WHERE operation_id=?",
                                  (key,)).fetchone()[0] == 0
        finally:
            with sqlite3.connect(dbpath) as db:
                db.execute(f"DROP TRIGGER IF EXISTS {trigger}")
        replies = [api(urls[0], "inventory_add", payload)]
        assert replies[0][0] == 200 and replies[0][1].get("success"), replies
        replies.append(api(urls[1], "inventory_add", payload))
        assert replies[1][1].get("idempotent_replay") is True
    else:
        replies = [api(urls[0], "inventory_add", payload)]
        replies.append(api(urls[1], "inventory_add", payload))
        assert replies[0][0] == replies[1][0] == 200
        assert replies[1][1].get("idempotent_replay") is True

    ids = {r["inventory_id"] for status, r in replies}
    assert len(ids) == 1, replies
    code, conflict = api(urls[1], "inventory_add",
                         {**payload, "quantity": 3})
    assert code == 409 and conflict.get("error") == "idempotency_conflict", conflict
    code, invalid = api(urls[0], "inventory_add",
                        {**payload, "_offline_op_id": "bad!"})
    assert code == 400 and invalid.get("error") == "invalid_offline_op_id", invalid

    with sqlite3.connect(dbpath) as db:
        stock = db.execute("SELECT SUM(quantity) FROM inventory WHERE product_id=?",
                           (pid,)).fetchone()[0]
        ledger = db.execute("SELECT SUM(quantity) FROM transactions WHERE product_id=? AND type='in'",
                            (pid,)).fetchone()[0]
        receipt = db.execute("SELECT action,request_hash,response_json FROM offline_write_receipts WHERE operation_id=?",
                             (key,)).fetchone()
        assert stock == 2 and ledger == 2, (stock, ledger)
        assert receipt and receipt[0] == "inventory_add"
        assert json.loads(receipt[2])["inventory_id"] in ids
        assert db.execute("PRAGMA quick_check").fetchone()[0] == "ok"
    print(f"PASS {scenario}: one stock/ledger write, durable receipt, safe replay and rejected key reuse")


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--sandbox-root", type=Path, required=True)
    p.add_argument("--base-url", default="http://127.0.0.1:18080")
    p.add_argument("--second-url", default="http://127.0.0.1:18081")
    args = p.parse_args()
    for url in (args.base_url, args.second_url):
        loc = urlparse(url)
        if loc.scheme != "http" or loc.hostname not in ("localhost", "127.0.0.1", "::1"):
            p.error("Refusing non-loopback endpoint")
    root = args.sandbox_root.resolve(strict=True)
    if not (root / ".env").is_file() or "EVERSHELF_SANDBOX=1" not in (root / ".env").read_text():
        p.error("Refusing unmarked database")
    dbpath = root / "data/evershelf.db"
    if not dbpath.is_file():
        p.error("Missing disposable database")
    # Clearing the disposable sandbox rate limiter enables repeated load runs,
    # without disabling any application rate limiting configuration.
    limits = root / "data/rate_limits"
    if limits.is_dir():
        for file in limits.glob("*.json"):
            file.unlink()
    urls = (args.base_url, args.second_url)
    for scenario in ("sequential", "concurrent", "fail_after_stock"):
        case(dbpath, urls, scenario)
    print("All server-side idempotent inventory additions passed.")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        sys.exit(1)
