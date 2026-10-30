#!/usr/bin/env python3
"""Test explicit manual-name conflicts with independent HTTP workers.

Refuses production endpoints; uses only a marked disposable DB and synthetic data.
Legacy (unguarded) scanner/catalog saves are deliberately tested separately.
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


def request(base, action, payload=None, query=""):
    req = Request(
        base.rstrip("/") + "/api/index.php?action=" + action + query,
        data=json.dumps(payload).encode() if payload is not None else None,
        method="POST" if payload is not None else "GET",
        headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"}
        if payload is not None else {},
    )
    try:
        with urlopen(req, timeout=35) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        try:
            return error.code, json.load(error)
        finally:
            error.close()


def run_case(root, bases, index):
    original = "Parallel Product Audit " + uuid.uuid4().hex[:14]
    body = {
        "name": original, "name_user_set": 1, "lang": "de",
        "category": "conserve", "unit": "conf",
        "default_quantity": 690, "package_unit": "g",
    }
    code, product = request(bases[0], "product_save", body)
    assert code == 200 and product.get("success") is True, (code, product)
    pid = int(product["id"])

    def edit(i):
        return request(bases[i % 2], "product_save", {
            **body, "id": pid,
            "name": original + " Writer" + chr(65+i),
            "expected_name": original,
        })
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(edit, range(8)))

    accepted = [(status, body) for status, body in results
                if status == 200 and body.get("success") is True]
    conflicts = [(status, body) for status, body in results
                 if status == 409 and body.get("error") == "product_conflict"]
    assert len(accepted) == 1 and len(conflicts) == 7, results
    code, read = request(bases[1], "product_get", query=f"&id={pid}")
    assert code == 200
    winner = read["product"]["name"]
    assert winner in [original + " Writer" + chr(65+i) for i in range(8)], winner

    # Once changed, stale requests must not modify unrelated product settings.
    code, rejected = request(bases[1], "product_save", {
        **body, "id": pid, "name": original + " Stale",
        "default_quantity": 9999, "expected_name": original,
    })
    assert code == 409 and rejected.get("success") is False, rejected

    with sqlite3.connect(f"file:{root / 'data/evershelf.db'}?mode=ro", uri=True) as db:
        row = db.execute("SELECT name,default_quantity FROM products WHERE id=?", (pid,)).fetchone()
        assert row == (winner, 690), row
        assert db.execute("PRAGMA quick_check").fetchone()[0] == "ok"

    # Legacy unguarded manual/catalog callers are not made incompatible.
    code, legacy = request(bases[0], "product_save", {
        **body, "id": pid, "name": winner, "name_user_set": 0,
    })
    assert code == 200 and legacy.get("success") is True
    print(f"round={index} one writer saved, seven conflicts rejected, legacy accepted, DB consistent")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--sandbox-root", required=True, type=Path)
    parser.add_argument("--base-url", default="http://127.0.0.1:18080")
    parser.add_argument("--second-url", default="http://127.0.0.1:18081")
    args = parser.parse_args()
    for base in (args.base_url, args.second_url):
        url = urlparse(base)
        if url.scheme != "http" or url.hostname not in ("localhost", "127.0.0.1", "::1"):
            parser.error("Only loopback test URLs are allowed")
    root = args.sandbox_root.resolve(strict=True)
    env = root / ".env"
    if not env.is_file() or "EVERSHELF_SANDBOX=1" not in env.read_text():
        parser.error("Missing disposable sandbox marker")
    if not (root / "data/evershelf.db").is_file():
        parser.error("Missing test database")
    for round_id in range(1, 4):
        run_case(root, (args.base_url, args.second_url), round_id)
    print("All multi-worker product edit conflict tests passed.")


if __name__ == "__main__":
    try:
        main()
    except (AssertionError, OSError, ValueError, json.JSONDecodeError) as exc:
        print("FAIL:", exc, file=sys.stderr)
        sys.exit(1)
