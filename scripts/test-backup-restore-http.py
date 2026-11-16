#!/usr/bin/env python3
"""Real HTTP restore tests using ONLY an automatically deleted SQLite fixture."""
from __future__ import annotations
from pathlib import Path
import json
import runpy
import shutil
import sqlite3
import tempfile
from urllib.error import HTTPError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
fixture = runpy.run_path(str(ROOT / "scripts/test-api-token-autogen.py"), run_name="restore_http_fixture")
sandbox, Server = fixture["sandbox"], fixture["Server"]

def check(condition, label):
    assert condition, label
    print("PASS",label,flush=True)

def post(s,filename):
    req=Request(s.url+"backup_restore",method="POST",
                data=json.dumps({"filename":filename}).encode(),
                headers={"Content-Type":"application/json","X-EverShelf-Request":"1"})
    try:
        with urlopen(req,timeout=24) as res:return res.status,json.load(res)
    except HTTPError as e:
        with e:return e.code,json.load(e)

def read_marker(p):
    with sqlite3.connect(p) as conn:
        return conn.execute("SELECT value FROM app_settings WHERE key='restore_http_marker'").fetchone()[0]

def write_marker(p,value):
    with sqlite3.connect(p) as conn:
        conn.execute("INSERT OR REPLACE INTO app_settings(key,value) VALUES (?,?)",
                     ("restore_http_marker", value))
        conn.commit()

with tempfile.TemporaryDirectory(prefix="evershelf-restore-http-qa-") as tmp:
    p=sandbox(Path(tmp)/"server",{"ALLOW_OPEN_LAN":"true"})
    for rel in ("api/index.php","api/lib/local_backup_restore.php"):
        (p/rel).parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(ROOT/rel,p/rel)
    server=Server(p)
    try:
        code,data=server.get("app_bootstrap")
        check(code==200 and not data.get("api_token_required"),
              "fresh isolated HTTP sandbox booted explicitly open for tests")
        live=p/"data/evershelf.db"
        backupdir=p/"data/backups"
        backupdir.mkdir(exist_ok=True)
        backupname="evershelf_2026-11-16_0712.db"
        backup=backupdir/backupname
        write_marker(live,"restorable-backup-value")
        with sqlite3.connect(live) as conn:
            conn.execute("VACUUM INTO ?",(str(backup),))
            conn.commit()
        with sqlite3.connect(f"file:{backup}?mode=ro",uri=True) as conn:
            check(conn.execute("PRAGMA quick_check").fetchone()[0]=="ok",
                  "offline fixture backup is valid SQLite")
        write_marker(live,"latest-live-value")
        cache=p/"data/smart_shopping_cache.json"
        cache.write_text('{"sentinel":"must remain if restore fails"}')

        for name,content,key in [
            ("evershelf_2026-11-16_0713.db",b"NOT-SQLITE", "error.backup_restore_invalid"),
            ("evershelf_2026-11-16_0714.db",b"", "error.backup_restore_invalid"),
            ("evershelf_2026-11-16_0715.db",backup.read_bytes()[:1500],"error.backup_restore_invalid")
        ]:
            (backupdir/name).write_bytes(content)
            status,result=post(server,name)
            check(result.get("success") is False and result.get("error_key")==key,
                  name+" rejected without reporting a successful restore")
            check(read_marker(live)=="latest-live-value",
                  name+" leaves existing user preferences intact")
            check(cache.is_file(),"failed restore never invalidates the current cache")
        badschema=backupdir/"evershelf_2026-11-16_0716.db"
        with sqlite3.connect(badschema) as conn:
            conn.execute("CREATE TABLE unrelated(value TEXT)")
            conn.commit()
        status,result=post(server,badschema.name)
        check(result.get("error_key")=="error.backup_restore_invalid",
              "schema-invalid but structurally valid SQLite backup rejected")
        check(read_marker(live)=="latest-live-value",
              "schema invalidity never alters live household")
        status,result=post(server,"../config.php")
        check(result.get("error_key")=="error.backup_invalid_name",
              "backup filename traversal blocked")
        status,result=post(server,"evershelf_2026-11-16_0700.db")
        check(result.get("error_key")=="error.backup_not_found",
              "missing backup reports not found without touching SQLite")
        check(read_marker(live)=="latest-live-value",
              "all invalid restore attempts preserve the live marker")

        status,result=post(server,backupname)
        check(status==200 and result.get("success") is True
              and result.get("message_key")=="settings.backup.restore_done",
              "valid HTTP backup restore returns success only after verification")
        check(read_marker(live)=="restorable-backup-value",
              "the actual household DB now contains restored values")
        check(not cache.exists(),"dependent shopping cache invalidated on success")
        with sqlite3.connect(f"file:{live}?mode=ro",uri=True) as db:
            check(db.execute("PRAGMA quick_check").fetchone()[0]=="ok",
                  "restored main DB independently passes quick_check")
        check(not list((p/"data").glob("restore-safety-*")),
              "successful online restore leaves no private recovery file")
        # The live DB remains usable after the first restore, with its WAL journal.
        write_marker(live,"more-recent-write-after-restore")
        status,result=post(server,backupname)
        check(status==200 and result.get("success") is True,
              "second restore on already restored HTTP server succeeds")
        check(read_marker(live)=="restorable-backup-value",
              "repeat restore deterministically recovers expected records")
        with sqlite3.connect(live) as db:
            db.execute("INSERT OR REPLACE INTO app_settings(key,value) VALUES (?,?)",
                       ("post_restore_write","available"))
            db.commit()
        check(read_marker(live)=="restorable-backup-value",
              "database can still accept writes after online restore")
    finally:
        server.close()
print("PASS disposable HTTP restore and corruption fixtures removed",flush=True)
