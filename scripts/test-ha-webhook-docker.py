#!/usr/bin/env python3
"""Run the shipped Apache/cron image with no external network or host volumes."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import uuid

image = sys.argv[1] if len(sys.argv) >= 2 else "evershelf-test"
unraid = "--unraid" in sys.argv[2:]
name = "evershelf-qa-outbox-" + uuid.uuid4().hex[:12]
token = "qa-docker-only-token"

def docker(*args, **kwargs):
    return subprocess.run(["docker", *args], check=True, text=True,
                          capture_output=True, timeout=kwargs.pop("timeout", 30), **kwargs).stdout.strip()

def inside(*args, **kwargs):
    return docker("exec", name, *args, **kwargs)

def check(ok, message):
    assert ok, message
    print("PASS", message, flush=True)

def post(action, data):
    start = time.monotonic()
    result = json.loads(inside("curl", "-fsS", "--max-time", "15",
        "-H", "Content-Type: application/json", "-H", "X-EverShelf-Request: 1",
        "-H", "X-API-Token: " + token, "-d", json.dumps(data),
        "http://127.0.0.1/api/index.php?action=" + action))
    check(result.get("success") is True, action + " acknowledged")
    return result, time.monotonic() - start

def scalar(sql, database="/var/www/html/data/evershelf.db"):
    return inside("sqlite3", database, sql)

def received():
    return [json.loads(line) for line in
            inside("sh", "-c", "cat /tmp/qa-received.jsonl 2>/dev/null || true").splitlines()]

def wait_for_event(event, after, started):
    deadline = started + 24
    while time.monotonic() < deadline:
        matches = [r for r in received()[after:] if r["body"]["event"] == event]
        if matches:
            elapsed = time.monotonic() - started
            check(elapsed < 24, f"automatic cron delivery {event} within {elapsed:.2f}s")
            return matches[0]
        time.sleep(.4)
    raise AssertionError("no scheduled delivery: " + event)

with tempfile.TemporaryDirectory(prefix="evershelf-docker-outbox-") as temp:
    p = Path(temp)
    (p / ".env").write_text(
        "EVERSHELF_SANDBOX=1\nAPI_TOKEN="+token+"\nHA_ENABLED=true\n"
        "HA_WEBHOOK_ASYNC=true\nHA_URL=http://127.0.0.1:18123\n"
        "HA_WEBHOOK_ID=qa-docker-only\nHA_WEBHOOK_EVENTS=shopping_add,stock_update\n"
        "HA_NOTIFY_SERVICE=\nNOTIFY_ENABLED=false\nAI_ENABLED=false\n"
        "BACKUP_ENABLED=false\nGDRIVE_ENABLED=false\nSHOPPING_MODE=internal\nLOG_LEVEL=DEBUG\n"
    )
    (p / "receiver.php").write_text("""<?php
$body = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($body)) { http_response_code(400); exit; }
file_put_contents('/tmp/qa-received.jsonl', json_encode(['path'=>$_SERVER['REQUEST_URI'],'body'=>$body])."\\n", FILE_APPEND | LOCK_EX);
usleep(3000000);
header('Content-Length: 0');
""")
    try:
        # Faster polling for this test only; the image keeps its real production thresholds.
        health_args=("--health-interval=2s","--health-retries=1","--health-start-period=0s")
        if unraid:
            (p / "config").mkdir()
            (p / "config/.env").write_bytes((p / ".env").read_bytes())
            docker("create", "--network", "none", "--name", name, *health_args, "-e", "EVERSHELF_ENV_FILE=/config/.env", image)
            docker("cp", str(p / "config"), name+":/config")
        else:
            docker("create", "--network", "none", "--name", name, *health_args, image)
            docker("cp", str(p / ".env"), name+":/var/www/html/.env")
        docker("cp", str(p / "receiver.php"), name+":/tmp/qa-receiver.php")
        docker("start", name)
        docker("exec", "-d", "-u", "www-data", name, "php", "-S",
               "127.0.0.1:18123", "/tmp/qa-receiver.php")
        for _ in range(40):
            try:
                inside("curl", "-fsS", "--max-time", "2", "http://127.0.0.1/")
                break
            except subprocess.CalledProcessError:
                time.sleep(.5)
        else:
            raise AssertionError("Apache did not start")
        # A freshly started cron daemon first schedules jobs at the next whole
        # minute. Wait for that initial tick before measuring steady-state latency.
        print("Waiting for initial cron tick (container startup only)", flush=True)
        deadline = time.monotonic() + 75
        while time.monotonic() < deadline:
            if inside("sh", "-c", "test -f /var/www/html/data/cron.log && echo ready || true") == "ready":
                break
            time.sleep(.5)
        else:
            raise AssertionError("cron did not start scheduling jobs")
        if unraid:
            # Emulate cron's deliberately stripped environment, not docker exec's.
            resolved=inside("env","-u","EVERSHELF_ENV_FILE","php","-r",
                "define('CRON_MODE',true); require '/var/www/html/api/lib/env.php'; echo evershelfEnvFile();")
            check(resolved=="/config/.env","cron resolves external config without inherited environment")
            check(inside("stat","-c","%a:%U","/run/evershelf-env-file")=="644:root",
                  "config handoff is root-owned and not writable by web user")
        # No manual worker invocation: verify actual cron delivery.
        start = time.monotonic()
        a, elapsed = post("shopping_add", {"items":[{"name":"Reis","specification":"1"}]})
        check(a["added"] == 1 and elapsed < 2.5, f"shopping API {elapsed:.3f}s")
        check(scalar("SELECT count(*) FROM shopping_list") == "1", "shopping persisted")
        r = wait_for_event("shopping_add", 0, start)
        check(r["path"] == "/api/webhook/qa-docker-only", "expected receiver path")
        product, _ = post("product_save", {"name":"Bohnen","name_user_set":1,"lang":"de",
                              "category":"conserve","unit":"conf","default_quantity":1,"package_unit":"conf"})
        post("inventory_add", {"product_id":product["id"],"quantity":2,"unit":"conf","location":"dispensa"})
        iid = int(scalar("SELECT id FROM inventory WHERE product_id="+str(int(product["id"]))))
        start = time.monotonic()
        _, elapsed = post("inventory_update", {"id":iid,"quantity":4,"location":"dispensa"})
        check(elapsed < 2.5, f"stock API {elapsed:.3f}s")
        r = wait_for_event("stock_update", 1, start)
        check(r["body"]["quantity"] == 4, "correct stock payload")
        check(scalar("SELECT quantity FROM inventory WHERE id="+str(iid)) in ("4","4.0"), "stock persisted")
        check(scalar("PRAGMA quick_check") == "ok", "pantry SQLite integrity")
        outbox = "/var/www/html/data/ha_webhook_outbox"
        check(inside("stat","-c","%a:%U",outbox) == "700:www-data", "private web-user outbox")
        check(inside("stat","-c","%a:%U",outbox+"/events.db") == "600:www-data", "private queue database")
        check(inside("curl","-sS","-o","/dev/null","-w","%{http_code}",
                      "http://127.0.0.1/data/ha_webhook_outbox/events.db") == "403",
              "Apache denies queue database HTTP access")
        check(inside("curl","-sS","-o","/dev/null","-w","%{http_code}",
                      "http://127.0.0.1/api/cron_ha_webhooks.php") == "403",
              "Apache denies HTTP worker execution")
        # One more cron tick ensures neither accepted event is replayed.
        time.sleep(11)
        check(len(received()) == 2, "no duplicate deliveries in subsequent cron ticks")
        check(scalar("SELECT count(*) FROM events",outbox+"/events.db") == "0", "queue drained")
        st=json.loads(inside("runuser","-u","www-data","--","php",
                            "/var/www/html/api/cron_ha_webhooks.php","--health"))
        check(st["ok"] and st["state"]=="running" and st["pending"]==0,"worker health reports fresh idle heartbeat")
        cfg=json.loads(inside("curl","-fsS","-H","X-API-Token: "+token,
                             "http://127.0.0.1/api/index.php?action=get_settings"))
        check(cfg["ha_webhook_worker"]["ok"] and cfg["ha_webhook_worker"]["pending"]==0,
              "authenticated settings exposes safe worker status")
        # Wait for the actual Docker health probe to observe the running worker.
        deadline=time.monotonic()+35
        while time.monotonic()<deadline:
            state=docker("inspect","--format","{{.State.Health.Status}}",name)
            if state=="healthy": break
            time.sleep(1)
        check(state=="healthy","container healthcheck passes with a healthy worker")
        # Emulate a stalled worker in the disposable container. Hold the real
        # worker lock while aging its heartbeat, without waiting 150 seconds.
        # Scheduled workers cannot refresh it until the lock is released.
        lock_code = ("$d='/var/www/html/data/ha_webhook_outbox'; "
                     "$f=fopen($d.'/worker.lock','c'); "
                     "if(!flock($f,LOCK_EX)) exit(1); "
                     "touch($d.'/worker.heartbeat',time()-151); "
                     "file_put_contents('/tmp/qa-worker-stalled','ready'); sleep(12);")
        docker("exec","-d","-u","www-data",name,"php","-r",lock_code)
        deadline=time.monotonic()+10
        while time.monotonic()<deadline:
            if inside("sh","-c","test -f /tmp/qa-worker-stalled && echo ready || true")=="ready": break
            time.sleep(.2)
        else: raise AssertionError("worker fault fixture did not lock")
        deadline=time.monotonic()+8
        while time.monotonic()<deadline:
            state=docker("inspect","--format","{{.State.Health.Status}}",name)
            if state=="unhealthy": break
            time.sleep(.3)
        check(state=="unhealthy","actual Docker healthcheck detects stale heartbeat")
        # A local write remains available when the optional worker is unhealthy.
        _, elapsed=post("inventory_update",{"id":iid,"quantity":5,"location":"dispensa"})
        check(elapsed<2.5 and scalar("SELECT quantity FROM inventory WHERE id="+str(iid)) in ("5","5.0"),
              "unhealthy worker does not block pantry API")
        deadline=time.monotonic()+35
        while time.monotonic()<deadline:
            state=docker("inspect","--format","{{.State.Health.Status}}",name)
            if state=="healthy" and len(received())==3: break
            time.sleep(.5)
        check(state=="healthy" and len(received())==3,"automatic health recovery and queued event delivery")
        check(received()[-1]["body"]["quantity"]==5,"recovered worker sends correct new stock")


    except Exception:
        # Synthetic container only: diagnostics exclude .env and credentials.
        for command in (["docker","logs","--tail","40",name],
                        ["docker","exec",name,"sh","-c","ls -ld /var/www/html/data /var/www/html/data/ha_webhook_outbox /var/www/html/data/ha_webhook_outbox/* /etc/cron.d/evershelf /var/www/html/.env 2>/dev/null; cat /etc/cron.d/evershelf; tail -40 /var/www/html/data/cron.log 2>/dev/null; tail -40 /var/www/html/logs/*.log 2>/dev/null; ps aux | grep -E 'cron|php -S' | head -20"]):
            result=subprocess.run(command,capture_output=True,text=True,timeout=10)
            print(result.stdout,result.stderr,flush=True)
        raise
    finally:
        subprocess.run(["docker","rm","-f",name],capture_output=True,timeout=30)
