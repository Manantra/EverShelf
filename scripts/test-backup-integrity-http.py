#!/usr/bin/env python3
"""Actual backup_now HTTP and SQLite/WAL verification on a disposable server.

No production data or environment is ever opened. The fixture contains a fresh
SQLite database copied from git and local code under test only.
"""
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import json
import shutil
import sqlite3
import tempfile
import runpy

ROOT = Path(__file__).resolve().parent.parent
fixture = runpy.run_path(str(ROOT / "scripts/test-api-token-autogen.py"), run_name="qa_fixture")
sandbox, Server = fixture["sandbox"], fixture["Server"]

def check(ok, label):
    assert ok, label
    print("PASS", label, flush=True)

with tempfile.TemporaryDirectory(prefix="evershelf-backup-http-qa-") as directory:
    p = sandbox(Path(directory) / "server", {"ALLOW_OPEN_LAN": "true"})
    for name in ("api/index.php", "api/lib/local_backup_integrity.php"):
        (p/name).parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT/name, p/name)
    server = Server(p)
    try:
        status, bootstrap = server.get("app_bootstrap")
        check(status == 200 and bootstrap.get("api_token_required") is False,
              "isolated HTTP backup fixture deliberately uses open-LAN sandbox")
        dbfile = p / "data/evershelf.db"
        with sqlite3.connect(dbfile) as conn:
            conn.execute("PRAGMA journal_mode=WAL")
            conn.execute("INSERT OR REPLACE INTO app_settings (key, value) VALUES (?, ?)",
                         ("backup_integrity_qa", json.dumps("retained_after_snapshot")))
            conn.commit()
        request = Request(server.url + "backup_now",
                          data=b"{}", method="POST",
                          headers={"Content-Type": "application/json", "X-EverShelf-Request": "1"})
        try:
            with urlopen(request, timeout=18) as res:
                status, result = res.status, json.load(res)
        except HTTPError as e:
            with e: status, result = e.code, json.load(e)
        check(status == 200 and result.get("success") is True,
              "real HTTP backup_now returns successful snapshot result")
        backup = p / "data/backups" / result["filename"]
        check(backup.is_file() and backup.stat().st_size > 4096,
              "finished backup file was published from .part")
        check(not list((p/"data/backups").glob("*.part")),
              "temporary partial backup file removed")
        with sqlite3.connect(f"file:{backup}?mode=ro", uri=True) as conn:
            check(conn.execute("PRAGMA quick_check").fetchone()[0] == "ok",
                  "published SQLite backup passes independent quick_check")
            saved = conn.execute("SELECT value FROM app_settings WHERE key='backup_integrity_qa'").fetchone()
            check(saved and json.loads(saved[0]) == "retained_after_snapshot",
                  "WAL-committed row exists in the published snapshot")
        receipt = json.loads((p / "data/backup_last_ts.json").read_text())
        check(receipt.get("filename") == result["filename"],
              "last-successful-backup receipt references validated final file")
    finally:
        server.close()
print("PASS disposable HTTP/SQLite backup fixture removed", flush=True)
