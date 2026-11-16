#!/usr/bin/env python3
"""Regression: optional retention config must not disable daily backup.sh.

Runs the real shell script from an isolated directory against disposable SQLite
files. In particular: the absence of BACKUP_RETENTION_DAYS must not cause the
grep|tail|cut pipeline to exit early under set -euo pipefail. Never reads or
modifies a user's actual .env, database, or backup directory.
"""
from __future__ import annotations

import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import time

BACKUP_SH = Path(__file__).resolve().parent.parent / "backup.sh"
assert BACKUP_SH.is_file()

def check(ok: bool, description: str) -> None:
    assert ok, description
    print("PASS", description, flush=True)

def case(name: str, env_lines: str | None, expected_retained: int,
         prior_count: int = 4, create_db: bool = True) -> None:
    with tempfile.TemporaryDirectory(prefix="evershelf-retention-qa-") as path:
        root = Path(path)
        copy = root / "backup.sh"
        shutil.copy2(BACKUP_SH, copy)
        if env_lines is not None:
            (root / ".env").write_text("EVERSHELF_SANDBOX=1\n" + env_lines,
                                      encoding="utf-8")
        (root / "data/backups").mkdir(parents=True)
        backup_dir = root / "data/backups"
        oldest = []
        for i in range(prior_count):
            file = backup_dir / f"evershelf_2001-01-0{i+1}_0000.db"
            # Fixture-only old files. Their contents don't matter: retention
            # only compares filenames and modification times.
            file.write_bytes(f"previous-backup-{i}".encode())
            os.utime(file, (946684800 + i * 60, 946684800 + i * 60))
            oldest.append(file)

        conn = None
        if create_db:
            db = root / "data/evershelf.db"
            conn = sqlite3.connect(db)
            conn.execute("PRAGMA journal_mode=WAL")
            conn.execute("CREATE TABLE verified (value TEXT NOT NULL)")
            conn.execute("INSERT INTO verified VALUES (?)",
                         (f"committed-in-WAL-{name}",))
            conn.commit()
        try:
            proc = subprocess.run(["bash", str(copy)], cwd=root,
                                  text=True, capture_output=True, timeout=25)
            check(proc.returncode == 0,
                  f"{name}: script returns success (status {proc.returncode})")
            backups = sorted(backup_dir.glob("evershelf_*.db"))
            count = min(expected_retained, prior_count + 1) if create_db else prior_count
            check(len(backups) == count,
                  f"{name}: retains exactly {count} newest backups")
            check(not list(backup_dir.glob("*.part")),
                  f"{name}: does not leave an unfinished .part file")
            if create_db:
                fresh = [b for b in backups if b.name not in {o.name for o in oldest}]
                check(len(fresh) == 1, f"{name}: new dated backup was created")
                with sqlite3.connect(f"file:{fresh[0]}?mode=ro", uri=True) as restored:
                    check(restored.execute("PRAGMA quick_check").fetchone()[0] == "ok",
                          f"{name}: published backup passes SQLite quick_check")
                    row = restored.execute("SELECT value FROM verified").fetchone()
                    check(row is not None and row[0] == f"committed-in-WAL-{name}",
                          f"{name}: backup contains committed WAL row")
                remaining_old = [f for f in oldest if f.exists()]
                expected_old = max(0, count - 1)
                check(len(remaining_old) == expected_old,
                      f"{name}: only {expected_old} prior backups remain")
                check(remaining_old == oldest[-expected_old:] if expected_old
                      else not remaining_old,
                      f"{name}: oldest prior backups were the ones removed")
            else:
                check(all(f.exists() for f in oldest),
                      f"{name}: no DB means no backup deletion")
        finally:
            if conn is not None:
                conn.close()

def failed_database() -> None:
    with tempfile.TemporaryDirectory(prefix="evershelf-retention-failure-") as path:
        root = Path(path)
        shutil.copy2(BACKUP_SH, root / "backup.sh")
        (root / ".env").write_text("EVERSHELF_SANDBOX=1\n", encoding="utf-8")
        (root / "data/backups").mkdir(parents=True)
        original = root / "data/backups/evershelf_2001-01-01_0000.db"
        original.write_bytes(b"old backup must stay")
        (root / "data/evershelf.db").write_bytes(b"definitely not a SQLite database")
        proc = subprocess.run(["bash", str(root / "backup.sh")], cwd=root,
                              text=True, capture_output=True, timeout=20)
        check(proc.returncode != 0, "corrupt source DB fails instead of silently succeeding")
        check(original.read_bytes() == b"old backup must stay",
              "failed run does not remove previous backups")
        check(not list((root / "data/backups").glob("*.part")),
              "failed run cleans temporary backup")

def main() -> None:
    # Both no .env and .env without the optional key need to work. The latter
    # is the upstream bug: grep returns 1, pipefail propagates the failure.
    case("missing-env", None, 3)
    case("missing-key", "APP_LANG=de\n", 3)
    case("empty-value", "BACKUP_RETENTION_DAYS=\n", 3)
    case("zero-value", "BACKUP_RETENTION_DAYS=0\n", 3)
    case("invalid-value", "BACKUP_RETENTION_DAYS=invalid\n", 3)
    case("explicit-two", "BACKUP_RETENTION_DAYS=2\n", 2)
    case("last-occurrence", "BACKUP_RETENTION_DAYS=1\nBACKUP_RETENTION_DAYS=2\n", 2)
    case("no-source-db", "APP_LANG=de\n", 3, create_db=False)
    failed_database()
    print("PASS isolated retention and failure matrix; all disposable files removed", flush=True)

if __name__ == "__main__":
    main()
