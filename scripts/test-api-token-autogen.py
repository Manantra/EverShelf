#!/usr/bin/env python3
"""Disposable HTTP integration checks for v1.16.24 API-token first start.

Never operates on the current checkout's .env or data. All installations are
copied into TemporaryDirectory and bind only to 127.0.0.1 with random ports.
No tokens or pairing codes are printed.
"""
from __future__ import annotations

import concurrent.futures
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tarfile
import tempfile
import time
from urllib.error import HTTPError
from urllib.parse import quote
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
PHP = shutil.which("php")
assert PHP, "php required"


def sandbox(root: Path, options: dict[str, str] | None = None) -> Path:
    root.mkdir()
    with subprocess.Popen(
        ["git", "-C", str(ROOT), "archive", "HEAD", "api", "translations", ".env.example"],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE
    ) as git:
        with tarfile.open(fileobj=git.stdout, mode="r|") as archive:
            archive.extractall(root, filter="data")
        err = git.stderr.read()
        assert git.wait() == 0, err.decode(errors="replace")
    # Include uncommitted local changes when running before the branch is pushed.
    for name in ("api/bootstrap.php", "api/lib/token_init.php"):
        path = root / name
        path.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / name, path)
    (root / "data").mkdir(exist_ok=True)
    opts = {
        "EVERSHELF_SANDBOX": "1", "APP_LANG": "de", "API_TOKEN": "",
        "API_BOOTSTRAP_OPEN": "false", "DEMO_MODE": "false",
        "AI_ENABLED": "false", "HA_ENABLED": "false",
        "NOTIFY_ENABLED": "false", "GDRIVE_ENABLED": "false",
    }
    opts.update(options or {})
    (root / ".env").write_text("".join(f"{key}={val}\n" for key, val in opts.items()))
    return root


def config(p: Path) -> dict[str, str]:
    return dict(x.split("=", 1) for x in (p / ".env").read_text().splitlines() if "=" in x)


def port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class Server:
    def __init__(self, root: Path, extra_env: dict[str, str] | None = None):
        self.port = port()
        self.url = f"http://127.0.0.1:{self.port}/api/index.php?action="
        env = os.environ.copy()
        env.update(extra_env or {})
        # Logs contain a pairing secret; never write them to the test output.
        self.proc = subprocess.Popen(
            [PHP, "-S", f"127.0.0.1:{self.port}", "-t", str(root)],
            cwd=root, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
        )
        for _ in range(70):
            if self.proc.poll() is not None:
                raise RuntimeError("Staging PHP server exited unexpectedly")
            try:
                with socket.create_connection(("127.0.0.1", self.port), timeout=.1):
                    break
            except OSError:
                time.sleep(.05)
        else:
            raise RuntimeError("Staging PHP server never started")

    def get(self, action="app_bootstrap", token: str | None = None):
        headers = {"X-API-Token": token} if token else {}
        req = Request(self.url + action, headers=headers)
        try:
            with urlopen(req, timeout=16) as res:
                return res.status, json.load(res)
        except HTTPError as err:
            with err:
                return err.code, json.load(err)

    def close(self):
        self.proc.terminate()
        try:
            self.proc.wait(timeout=4)
        except subprocess.TimeoutExpired:
            self.proc.kill()
            self.proc.wait()


def run_server(p: Path, callback, extra_env=None):
    s = Server(p, extra_env=extra_env)
    try:
        return callback(s)
    finally:
        s.close()


def check(cond, title):
    assert cond, title
    print("PASS", title, flush=True)


def fresh(p: Path):
    def test(s):
        status, data = s.get()
        check(status == 200 and data.get("api_token_required")
              and data.get("pairing_required")
              and "api_token" not in data, "fresh first HTTP request requires pairing")
        new_token = config(p)["API_TOKEN"]
        check(len(new_token) == 64 and all(c in "0123456789abcdef" for c in new_token),
              "random 256-bit token stored in .env")
        check((p / "data/api_token_autogen.json").exists(),
              "secret-free initialization marker persisted")
        check(not (p / "data/api_token_autogen.pending").exists(),
              "temporary initialization marker cleaned")
        marker = json.loads((p / "data/api_token_autogen.json").read_text())
        check("token" not in marker and marker["storage"] == "env",
              "marker has storage metadata but no secret")
        status, denied = s.get("inventory_list")
        check(status == 401 and denied.get("error") == "unauthorized",
              "anonymous inventory denied")
        code = json.loads((p / "data/api_pairing.json").read_text())["code"]
        status, paired = s.get("app_bootstrap&pairing_code=" + quote(code))
        check(status == 200 and paired.get("api_token") == new_token,
              "valid one-time code pairs client")
        status, rejected = s.get("app_bootstrap&pairing_code=" + quote(code))
        check(status == 401 and "api_token" not in rejected,
              "consumed code cannot reveal token a second time")
        status, auth = s.get("get_settings", token=new_token)
        check(status == 200 and auth.get("api_token_required") is True,
              "newly paired client reads protected settings")
        status, reboot = s.get()
        check(status == 200 and reboot.get("pairing_required") is True
              and config(p)["API_TOKEN"] == new_token,
              "server restart-like next HTTP request does not rotate token")
        # Removing a generated token is not permission to silently mint a new one.
        (p / ".env").write_text((p / ".env").read_text().replace(
            "API_TOKEN=" + new_token, "API_TOKEN="))
        status, lost = s.get()
        check(status == 503 and lost.get("error") == "api_token_initialization_failed"
              and config(p)["API_TOKEN"] == "",
              "lost generated token fails closed instead of rotating")
    run_server(p, test)


def prepared_token(p: Path, legacy=False):
    token = "legacy-token-is-not-overwritten" if legacy else "already-paired-token"
    var = "SETTINGS_TOKEN" if legacy else "API_TOKEN"
    t = p / ".env"
    t.write_text(t.read_text() + f"{var}={token}\n")
    def test(s):
        status, resp = s.get()
        check(status == 200 and resp.get("api_token_required") and "api_token" not in resp,
              "existing configured credential requires auth")
        status, resp = s.get("get_settings", token=token)
        check(status == 200, "existing credential continues authenticating")
        check(config(p)[var] == token and not (p/"data/api_token_autogen.json").exists(),
              "existing credential unchanged, never auto-replaced")
    run_server(p, test)


def opt_out(p: Path, mode="ALLOW_OPEN_LAN"):
    t=p/".env"
    t.write_text(t.read_text() + mode + "=true\n")
    def test(s):
        status, out = s.get()
        check(status == 200 and not out.get("api_token_required")
              and "api_token" not in out, "explicit open-LAN/demo policy is respected")
        check(config(p)["API_TOKEN"] == "" and not (p/"data/api_token_autogen.json").exists(),
              "opt-out never mints a token")
    run_server(p, test)


def preexisting_db(p: Path, opt_in=False):
    # A real EverShelf database, created from CLI without invoking HTTP auto-init.
    script="require 'api/bootstrap.php'; getDB();"
    subprocess.run([PHP, "-r", script], cwd=p, check=True, stdout=subprocess.DEVNULL,
                   stderr=subprocess.DEVNULL)
    check((p/"data/evershelf.db").exists(), "existing-household DB fixture created")
    if opt_in:
        with (p/".env").open("a") as f:
            f.write("API_TOKEN_AUTOGENERATE=true\n")
    def test(s):
        status, out=s.get()
        if opt_in:
            token=config(p)["API_TOKEN"]
            check(status==200 and len(token)==64 and out.get("pairing_required") is True,
                  "existing household explicit opt-in generates token and pairing")
        else:
            check(status==200 and not out.get("api_token_required")
                  and config(p)["API_TOKEN"] == "",
                  "existing open-LAN household remains open, avoiding silent lockout")
            check((p/"data/api_token_legacy_open.notice").exists(),
                  "existing open household receives an upgrade warning")
    run_server(p,test)


def readonly_fallback(p: Path, external=False):
    target=p/(".readonly-external.env" if external else ".env")
    if external:
        target.write_text((p/".env").read_text())
    target.chmod(0o444)
    def test(s):
        status,out=s.get()
        check(status==200 and out.get("pairing_required") is True,
              "read-only config first request still requires pairing")
        assert not any(x.startswith("API_TOKEN=") and len(x)>10
                       for x in target.read_text().splitlines())
        with sqlite3.connect(p/"data/evershelf.db") as db:
            stored=db.execute("SELECT value FROM app_settings WHERE key='env_overrides'").fetchone()
        obj=json.loads(stored[0])["API_TOKEN"]
        token=obj["value"] if isinstance(obj,dict) else obj
        check(len(token)==64, "token persisted in SQLite override after .env write failure")
        marker=json.loads((p/"data/api_token_autogen.json").read_text())
        check(marker["storage"]=="env_overrides", "read-only fallback recorded without credential")
        status,secure=s.get("get_settings", token=token)
        check(status==200 and secure.get("api_token_required") is True,
              "read-only fallback authenticates current client")
        status,out=s.get()
        check(status==200 and out.get("pairing_required") is True,
              "fallback survives separate HTTP request with no token rotation")
    try:
        run_server(p,test,{"EVERSHELF_ENV_FILE":str(target)} if external else None)
    finally:
        target.chmod(0o600)


def concurrent_boot(p: Path):
    a,b=Server(p),Server(p)
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            fut=[pool.submit(s.get) for s in (a,b)]
            results=[f.result(timeout=20) for f in fut]
        check(all(status==200 and out.get("pairing_required") is True
                  and "api_token" not in out for status,out in results),
              "two concurrent web workers both show secure pairing")
        token=config(p)["API_TOKEN"]
        check(len(token)==64 and
              len(json.loads((p/"data/api_pairing.json").read_text())["code"]) == 8,
              "parallel boot produced one persistent token and one pairing state")
        check(a.get("get_settings",token)[0] == 200 and b.get("get_settings",token)[0] == 200,
              "same token authenticates across both HTTP processes")
        status,st=b.get()
        check(status==200 and st.get("pairing_required") and config(p)["API_TOKEN"]==token,
              "second worker does not rotate token")
    finally:
        a.close()
        b.close()


def interrupted_first_boot(p: Path):
    # An earlier fresh request might have created the DB and then crashed before
    # storing the token. The persisted pending marker must resume safely.
    subprocess.run([PHP, '-r', "require 'api/bootstrap.php'; getDB();"], cwd=p,
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    (p/'data/api_token_autogen.pending').write_text('initializing\n')
    def test(s):
        status, data=s.get()
        token=config(p)['API_TOKEN']
        check(status==200 and data.get('pairing_required') is True and len(token)==64,
              'interrupted initial start recovers instead of leaving API open')
        check(not (p/'data/api_token_autogen.pending').exists(),
              'successful recovery clears the pending marker')
    run_server(p,test)


def missing_env(p: Path):
    (p/'.env').unlink()
    def test(s):
        status,out=s.get()
        check(status==200 and out.get('pairing_required') is True,
              'missing .env is seeded and secured on first HTTP request')
        check(len(config(p).get('API_TOKEN',''))==64,
              'fresh .env persists a generated token')
    run_server(p,test)


def external_writable(p: Path):
    external=p/'writable-config.env'
    external.write_text((p/'.env').read_text())
    def test(s):
        status,out=s.get()
        check(status==200 and out.get('pairing_required') is True,
              'external EVERSHELF_ENV_FILE works for first request')
        ext=dict(x.split('=',1) for x in external.read_text().splitlines() if '=' in x)
        check(len(ext.get('API_TOKEN',''))==64 and config(p)['API_TOKEN']=='',
              'token persisted only to configured external file')
    run_server(p,test,{'EVERSHELF_ENV_FILE':str(external)})


def blocked_lock(p: Path):
    # Existing directory where an initialization lock file should be prevents
    # opening the lock even when the staging process runs as a privileged user.
    (p/'data/api_token_autogen.lock').mkdir()
    def test(s):
        status,out=s.get()
        check(status==503 and out.get('error')=='api_token_initialization_failed',
              'unavailable interprocess lock fails closed with HTTP 503')
        check(config(p)['API_TOKEN']=='' and not (p/'data/api_token_autogen.json').exists(),
              'lock failure must not mint or expose a token')
    run_server(p,test)


def blocked_persistence(p: Path):
    # Force both standard storage paths to fail: .env is immutable, and SQLite
    # cannot open a directory as a database file. This needs no root privileges.
    config_file=p/'.env'
    config_file.chmod(0o444)
    (p/'data/evershelf.db').mkdir()
    try:
        def test(s):
            status,out=s.get()
            check(status==503 and out.get('error')=='api_token_initialization_failed',
                  'failed .env and DB persistence fails closed with HTTP 503')
            check(config(p)['API_TOKEN']=='' and not (p/'data/api_token_autogen.json').exists(),
                  'failed persistence must not claim a generated token')
            # The pending marker ensures a repaired installation is not
            # misclassified as an older, intentionally open LAN household.
            (p/'data/evershelf.db').rmdir()
            config_file.chmod(0o600)
            status,restored=s.get()
            check(status==200 and restored.get('pairing_required') and len(config(p)['API_TOKEN'])==64,
                  'after storage repair, next request completes secure first boot')
        run_server(p,test)
    finally:
        config_file.chmod(0o600)


def cli_unchanged(p:Path):
    subprocess.run([PHP, "-r", "require 'api/bootstrap.php'; echo 'cli ok';"], cwd=p,
                   stdout=subprocess.PIPE, stderr=subprocess.PIPE,check=True)
    check(not (p/"data/api_token_autogen.json").exists() and config(p)["API_TOKEN"]=="",
          "CLI/cron bootstrap creates no token")


def main():
    with tempfile.TemporaryDirectory(prefix="evershelf-token-autogen-sandbox-") as temp:
        temp=Path(temp)
        cases=[
            ("fresh",fresh,{}),
            ("existing",lambda p:prepared_token(p),{}),
            ("legacy",lambda p:prepared_token(p,True),{}),
            ("open-lan",opt_out,{}),
            ("demo",lambda p:opt_out(p,"DEMO_MODE"),{}),
            ("existing-db",preexisting_db,{}),
            ("upgrade-opt-in",lambda p:preexisting_db(p,True),{}),
            ("readonly",readonly_fallback,{}),
            ("readonly-external",lambda p:readonly_fallback(p,True),{}),
            ("two-workers",concurrent_boot,{}),
            ("cli",cli_unchanged,{}),
            ("missing-env",missing_env,{}),
            ("external-writable",external_writable,{}),
            ("interrupted-boot",interrupted_first_boot,{}),
            ("blocked-lock",blocked_lock,{}),
            ("blocked-persistence",blocked_persistence,{})
        ]
        for name,test,opts in cases:
            print("CASE",name,flush=True)
            test(sandbox(temp/name,opts))
    print("PASS all one-time token first-boot checks; disposable instances removed")


if __name__=="__main__":
    main()
