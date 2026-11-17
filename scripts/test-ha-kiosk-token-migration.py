#!/usr/bin/env python3
"""Disposable Home Assistant / Android kiosk API migration compatibility tests.

These send real HTTP requests to a private PHP+SQLite sandbox, mimicking the
headers/methods that native clients and Home Assistant use. No production APIs.
"""
from pathlib import Path
from urllib.request import Request, urlopen
from urllib.error import HTTPError
from urllib.parse import urlencode
import json
import re
import runpy
import sqlite3
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent
fixture = runpy.run_path(str(ROOT / "scripts/test-api-token-autogen.py"), run_name="qa_fixture")
sandbox, Server = fixture["sandbox"], fixture["Server"]
php = fixture["PHP"]

def req(server, action, token=None, method="GET", style="x-api", payload=None):
    headers={}
    if token is not None:
        if style=="bearer": headers["Authorization"]="Bearer "+token
        elif style=="x-settings": headers["X-Settings-Token"]=token
        elif style=="x-api": headers["X-API-Token"]=token
        elif style=="query": action += ("&" if "?" in action or "&" in action else "&") + urlencode({"api_token":token})
        else: raise ValueError(style)
    data=json.dumps(payload).encode() if payload is not None else None
    if data is not None: headers["Content-Type"]="application/json"
    q=Request(server.url+action,method=method,data=data,headers=headers)
    try:
        with urlopen(q,timeout=16) as f: return f.status,json.load(f)
    except HTTPError as e:
        with e:return e.code,json.load(e)

def expect(condition, description):
    assert condition, description
    print("PASS",description,flush=True)

def verify_native_code():
    setup=(ROOT/"evershelf-kiosk/app/src/main/kotlin/it/dadaloop/evershelf/kiosk/SetupActivity.kt").read_text()
    native=(ROOT/"evershelf-kiosk/app/src/main/kotlin/it/dadaloop/evershelf/kiosk/KioskActivity.kt").read_text()
    settings=(ROOT/"evershelf-kiosk/app/src/main/kotlin/it/dadaloop/evershelf/kiosk/SettingsActivity.kt").read_text()
    expect('putString(KEY_API_TOKEN, token)' in setup and 'app_bootstrap&pairing_code=' in setup,
           "native Android pairing saves the one-time API token")
    expect('fun getApiToken(): String = prefs.getString("api_token", "")' in native,
           "native kiosk injects its saved token into WebView")
    expect('val localApi = "$baseUrl/api/index.php?action=kiosk_update"' in native,
           "native kiosk uses the public local OTA metadata endpoint")
    i=settings.index('val apiUrl = "$url/api/index.php?action=save_settings"')
    j=settings.index('}.start()',i)
    part=settings[i:j]
    expect('setRequestProperty("X-API-Token", apiToken)' in part,
           "native kiosk Settings POST includes paired token")
    expect('status !in 200..299' in part and 'R.string.settings_server_save_failed' in part,
           "native kiosk Settings rejects failed saves instead of displaying false success")

def migration_case(q):
    # Initialized legacy household that previously depended on an open LAN.
    subprocess.run([php,"-r","require 'api/bootstrap.php';getDB();"],cwd=q,check=True,
                   stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    s=Server(q)
    try:
        status,free=req(s,"ha_sensor")
        expect(status==200 and "state" in free,"legacy HA REST sensor works before opt-in")
        status,pre=req(s,"app_bootstrap")
        expect(status==200 and pre.get("api_token_required") is False,
               "legacy kiosk bootstrap works anonymously before opt-in")
        status,pre=req(s,"kiosk_update")
        expect(status==200,"legacy kiosk local update metadata is reachable")
        status,pre=req(s,"save_settings",method="POST",payload={"screensaver_enabled":True})
        expect(status==200 and pre.get("success") is True,
               "legacy kiosk JSON-only Settings POST works before opt-in")
        cfg=q/".env"
        cfg.write_text(cfg.read_text()+"API_TOKEN_AUTOGENERATE=true\n")
        status,denied=req(s,"ha_sensor")
        expect(status==401 and denied.get("error")=="unauthorized",
               "opt-in migration protects HA REST sensor on first protected request")
        config=dict(x.split("=",1) for x in cfg.read_text().splitlines() if "=" in x)
        token=config.get("API_TOKEN","")
        expect(len(token)==64,"migration persists exactly one new 256-bit token")
        status,unauth=req(s,"kiosk_update")
        expect(status==200 and unauth.get("error")!="unauthorized",
               "local kiosk OTA metadata stays public and reachable after migration")
        status,unauth=req(s,"save_settings",method="POST",payload={"screensaver_enabled":False})
        expect(status==401 and unauth.get("error")=="unauthorized",
               "kiosk JSON-only POST is not an auth bypass")
        status,info=req(s,"ha_info")
        expect(status==200 and info.get("api_token_required") is True
               and info.get("items_count") is None,
               "unauthenticated HA discovery reveals only safe metadata")
        for method,style in [("Bearer","bearer"),("X-API-Token","x-api"),
                             ("X-Settings-Token","x-settings"),("URL query","query")]:
            status,data=req(s,"ha_sensor",token,style=style)
            expect(status==200 and "state" in data,
                   "HA REST sensor accepts documented "+method+" credential")
        status,data=req(s,"ha_sensor","incorrect-token",style="bearer")
        expect(status==401 and data.get("error")=="unauthorized",
               "incorrect HA bearer token rejected")
        status,data=req(s,"kiosk_update",token,style="x-api")
        expect(status==200,"native kiosk OTA metadata also works with token")
        status,data=req(s,"get_settings",token,style="x-api")
        expect(status==200 and data.get("api_token_required") is True,
               "paired native kiosk Settings GET works")
        status,data=req(s,"save_settings",token,method="POST",payload={"screensaver_enabled":False})
        expect(status==200 and data.get("success") is True,
               "native kiosk JSON Settings POST works with stored token")
        status,data=req(s,"get_settings",token)
        expect(status==200 and data.get("screensaver_enabled") is False,
               "native kiosk Settings POST persists to protected configuration")
        status,data=req(s,"app_bootstrap")
        expect(status==200 and data.get("pairing_required") is True
               and "api_token" not in data,
               "unpaired Android kiosk sees pairing-required bootstrap")
        state=json.loads((q/"data/api_pairing.json").read_text())
        code=state["code"]
        assert re.fullmatch("[0-9a-f]{8}",code)
        status,data=req(s,"app_bootstrap&pairing_code="+code)
        expect(status==200 and data.get("api_token")==token,
               "native kiosk consumes one-time pairing code")
        status,data=req(s,"app_bootstrap&pairing_code="+code)
        expect(status==401 and "api_token" not in data,
               "native kiosk cannot reuse pairing code")
        status,data=req(s,"ha_sensor",token,style="bearer")
        expect(status==200,"existing HA token remains valid after kiosk pairs")
        with sqlite3.connect(q/"data/evershelf.db") as db:
            expect(db.execute('PRAGMA integrity_check').fetchone()[0]=="ok",
                   "migrated household SQLite remains healthy")
    finally:
        s.close()
    restarted=Server(q)
    try:
        now=dict(x.split("=",1) for x in (q/".env").read_text().splitlines() if "=" in x)
        expect(now.get("API_TOKEN")==token and req(restarted,"ha_sensor",token,style="bearer")[0]==200,
               "token is stable and HA can read after PHP service restart")
        expect(req(restarted,"get_settings",token,style="x-api")[0]==200,
               "native kiosk stored token remains valid after restart")
    finally:
        restarted.close()

def unchanged_token_case(q,legacy=False):
    token="existing-household-token-not-rotated"
    cfg=q/".env"
    cfg.write_text(cfg.read_text()+("SETTINGS_TOKEN=" if legacy else "API_TOKEN=")+token+"\n")
    s=Server(q)
    try:
        st,out=req(s,"ha_sensor",token,style="bearer")
        expect(st==200,"legacy HA bearer works with already configured credential")
        st,out=req(s,"save_settings",token,method="POST",payload={"screensaver_enabled":True})
        expect(st==200 and out.get("success") is True,
               "already paired kiosk can save through native protocol")
        st,out=req(s,"get_settings",token,style="x-api")
        expect(st==200,"already paired kiosk can load preferences")
        expect(not (q/"data/api_token_autogen.json").exists() and
               token in cfg.read_text(),
               "previously configured credential is not rotated or overwritten")
    finally:
        s.close()

def main():
    verify_native_code()
    with tempfile.TemporaryDirectory(prefix="evershelf-ha-kiosk-qa-") as tmp:
        base=Path(tmp)
        migration_case(sandbox(base/"migration"))
        unchanged_token_case(sandbox(base/"already-paired"))
        unchanged_token_case(sandbox(base/"legacy-settings-token"),legacy=True)
    print("PASS simulated HA/kiosk migration matrix; disposable data removed",flush=True)

if __name__=="__main__":
    main()
