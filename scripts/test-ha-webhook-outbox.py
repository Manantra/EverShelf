#!/usr/bin/env python3
"""Isolated real HTTP API and outbox worker proof; no production connections."""
from pathlib import Path
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import concurrent.futures
import json, runpy, tempfile, threading, time, sqlite3, shutil, subprocess, stat, os

root=Path(__file__).resolve().parent.parent
fixture=runpy.run_path(str(root/"scripts/test-api-token-autogen.py"),run_name="qa")
sandbox,Server,PHP=fixture["sandbox"],fixture["Server"],fixture["PHP"]
received=[]
first=threading.Event()
crash_entered=threading.Event()
crash_next=threading.Event()
class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        data=json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        received.append(data)
        attempt=len(received)
        if attempt==1:
            first.set()
            time.sleep(6)
        if crash_next.is_set():
            crash_next.clear()
            crash_entered.set()
            time.sleep(6)
        self.send_response(503 if attempt==2 else 200)
        self.send_header("Content-Length","0")
        self.end_headers()
    def log_message(self,*args): pass
r=ThreadingHTTPServer(("127.0.0.1",0),Handler)
threading.Thread(target=r.serve_forever,daemon=True).start()

def post(s,action,payload):
    req=Request(s.url+action,data=json.dumps(payload).encode(),headers={"Content-Type":"application/json","X-API-Token":"qa-only-token","X-EverShelf-Request":"1"},method="POST")
    start=time.monotonic()
    try:
        with urlopen(req,timeout=20) as response:
            result=json.load(response)
    except HTTPError as e:
        raise AssertionError((action,e.code,e.read().decode())) from e
    elapsed=time.monotonic()-start
    assert result["success"],(action,result)
    return result,elapsed

def restore(s,filename):
    req=Request(s.url+"backup_restore",data=json.dumps({"filename":filename}).encode(),
                headers={"Content-Type":"application/json","X-API-Token":"qa-only-token","X-EverShelf-Request":"1"},method="POST")
    with urlopen(req,timeout=15) as response:
        return json.load(response)

def health(q):
    proc=subprocess.run([PHP,str(q/"api/cron_ha_webhooks.php"),"--health"],cwd=q,
                        text=True,capture_output=True,timeout=5)
    result=json.loads(proc.stdout)
    assert proc.returncode == (0 if result["ok"] else 1), proc
    assert set(result)=={"enabled","ok","state","last_run","pending","oldest_age"}, result
    return result

def count(q):
    with sqlite3.connect(q/"data/ha_webhook_outbox/events.db") as db:
        return db.execute("SELECT count(*) FROM events").fetchone()[0]

def check(condition,msg):
    assert condition,msg
    print("PASS",msg,flush=True)

def worker(q):
    return subprocess.Popen([PHP,str(q/"api/cron_ha_webhooks.php")],cwd=q,stdout=subprocess.PIPE,stderr=subprocess.PIPE)

def finish(w, timeout=25):
    out,err=w.communicate(timeout=timeout)
    assert w.returncode==0,(out.decode(),err.decode())

try:
    with tempfile.TemporaryDirectory(prefix="qa45-outbox-") as temp:
        q=sandbox(Path(temp)/"app",{"HA_ENABLED":"true","HA_WEBHOOK_ASYNC":"true","HA_URL":f"http://127.0.0.1:{r.server_port}","HA_WEBHOOK_ID":"qa-only-secret","HA_WEBHOOK_EVENTS":"shopping_add,stock_update","API_TOKEN":"qa-only-token","LOG_LEVEL":"DEBUG"})
        for name in ("api/index.php","api/lib/ha_webhook_outbox.php","api/cron_ha_webhooks.php","api/lib/local_backup_restore.php","api/lib/env.php"):
            shutil.copy2(root/name,q/name)
        s=Server(q)
        s2=None
        w=None
        try:
            check(health(q)["state"]=="waiting" and not (q/"data/ha_webhook_outbox").exists(),
                  "enabled worker with no heartbeat reported waiting without creating queue")
            finish(worker(q))
            check(stat.S_IMODE((q/"data/ha_webhook_outbox/worker.heartbeat").stat().st_mode)==0o600,"heartbeat private permissions")
            check(health(q)["state"]=="running" and health(q)["pending"]==0,
                  "idle worker reports heartbeat before first event")
            heartbeat=q/"data/ha_webhook_outbox/worker.heartbeat"
            old=time.time()-151
            os.utime(heartbeat,(old,old))
            check(health(q)["state"]=="stale","missing worker ticks detected")
            finish(worker(q))
            check(health(q)["state"]=="running","worker recovery updates heartbeat")
            a,elapsed=post(s,"shopping_add",{"items":[{"name":"QA Quinoa","specification":"1"}]})
            check(a["added"]==1 and elapsed<1.5,f"shopping API {elapsed:.3f}s with no receiver wait")
            with sqlite3.connect(q/"data/evershelf.db") as db:
                check(db.execute("SELECT count(*) FROM shopping_list").fetchone()[0]==1,"shopping row persisted")
            p,_=post(s,"product_save",{"name":"QA Beans","name_user_set":1,"lang":"de","category":"conserve","unit":"conf","default_quantity":1,"package_unit":"conf"})
            post(s,"inventory_add",{"product_id":p["id"],"quantity":2,"unit":"conf","location":"dispensa"})
            with sqlite3.connect(q/"data/evershelf.db") as db:
                iid=db.execute("SELECT id FROM inventory WHERE product_id=?",(p["id"],)).fetchone()[0]
            _,elapsed=post(s,"inventory_update",{"id":iid,"quantity":4,"location":"dispensa"})
            check(elapsed<1.5,f"inventory API {elapsed:.3f}s with no receiver wait")
            check(count(q)==2 and not received,"two events queued, no outgoing HTTP yet")
            st=health(q)
            check(st["ok"] and st["pending"]==2 and st["oldest_age"] is not None,
                  "health reports pending count without revealing payloads")
            with sqlite3.connect(q/"data/ha_webhook_outbox/events.db") as db:
                db.execute("UPDATE events SET created_at=?", (int(time.time())-151,))
            check(health(q)["state"]=="backlog","old queued events flagged separately")
            with sqlite3.connect(q/"data/ha_webhook_outbox/events.db") as db:
                db.execute("UPDATE events SET created_at=?", (int(time.time()),))

            try:
                urlopen(s.url.split("/api/")[0]+"/api/cron_ha_webhooks.php",timeout=3)
                raise AssertionError("worker exposed through HTTP")
            except HTTPError as e:
                check(e.code==403,"worker cannot be triggered over HTTP")
            check(count(q)==2 and not received,"HTTP worker request cannot claim an event")
            path=q/"data/ha_webhook_outbox"
            check(stat.S_IMODE(path.stat().st_mode)==0o700 and stat.S_IMODE((path/"events.db").stat().st_mode)==0o600,"private outbox permissions")
            with sqlite3.connect(path/"events.db") as db:
                rows=db.execute("SELECT event,payload,target_hash FROM events").fetchall()
            check(all("qa-only-secret" not in str(row) and "127.0.0.1" not in str(row) for row in rows),"no stored webhook ID or URL")
            s.close();s=Server(q)
            check(count(q)==2,"pending events survive PHP server restart")
            backups=q/"data/backups";backups.mkdir(exist_ok=True)
            backupname="evershelf_2026-11-27_0800.db"
            backup=backups/backupname
            with sqlite3.connect(q/"data/evershelf.db") as db:
                db.execute("VACUUM INTO ?",(str(backup),))
            badname="evershelf_2026-11-27_0801.db"
            (backups/badname).write_bytes(b"not SQLite")
            rejected=restore(s,badname)
            check(not rejected["success"] and rejected["error_key"]=="error.backup_restore_invalid"
                  and count(q)==2,"invalid backup leaves pending events intact")
            w=worker(q)
            check(first.wait(5),"worker reaches slow receiver")
            rejected=restore(s,backupname)
            check(not rejected["success"] and rejected["error_key"]=="error.backup_restore_busy",
                  "restore cannot race an in-flight worker")
            start=time.monotonic()
            finish(worker(q),timeout=4)
            check(time.monotonic()-start<2,"parallel worker returns without duplicate claim")
            s2=Server(q)
            def add(pair):
                server,name=pair
                return post(server,"shopping_add",{"items":[{"name":name,"specification":"1"}]})
            with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                outcomes=list(pool.map(add,[(s,"Reis"),(s2,"Linsen")]))
            check(all(a["added"]==1 and elapsed<1.5 for a,elapsed in outcomes),"two parallel API writes complete while worker waits")
            finish(w);w=None
            check(count(q)==0 and len(received)==4,"worker drains four events exactly once despite timeout and HTTP 503")
            check(received[0]["event"]=="shopping_add" and received[1]["event"]=="stock_update" and received[1]["quantity"]==4,"FIFO payload and stock quantity preserved")
            finish(worker(q))
            check(len(received)==4,"restart does not retry attempted events")
            with sqlite3.connect(q/"data/evershelf.db") as db:
                check(db.execute("SELECT quantity FROM inventory WHERE id=?",(iid,)).fetchone()[0]==4 and db.execute("PRAGMA quick_check").fetchone()[0]=="ok","stock persisted and pantry integrity ok")
            post(s,"shopping_add",{"items":[{"name":"QA Changed Target","specification":"1"}]})
            check(count(q)==1,"event queued before destination change")
            env=q/".env";env.write_text(env.read_text().replace("HA_WEBHOOK_ID=qa-only-secret","HA_WEBHOOK_ID=qa-new-secret"))
            finish(worker(q))
            check(count(q)==0 and len(received)==4,"old event not sent after destination change")
            post(s,"shopping_add",{"items":[{"name":"QA Stale Event","specification":"1"}]})
            with sqlite3.connect(path/"events.db") as db:
                db.execute("UPDATE events SET created_at=0")
            finish(worker(q))
            check(count(q)==0 and len(received)==4,"expired event dropped without sending")
            post(s,"shopping_add",{"items":[{"name":"QA Disabled Event","specification":"1"}]})
            env.write_text(env.read_text().replace("HA_ENABLED=true","HA_ENABLED=false"))
            finish(worker(q))
            check(count(q)==0 and len(received)==4,"disabled HA drops pending events")
            check(health(q)["state"]=="disabled" and health(q)["ok"],"disabled HA does not fail health")
            env.write_text(env.read_text().replace("HA_ENABLED=false","HA_ENABLED=true"))
            post(s,"shopping_add",{"items":[{"name":"Haferflocken","specification":"1"}]})
            check(count(q)==1,"one pending event for crash test")
            crash_next.set()
            w=worker(q)
            check(crash_entered.wait(5),"worker claimed event and reached receiver")
            w.kill();w.communicate();w=None
            finish(worker(q))
            check(count(q)==0 and len(received)==5,"worker killed after claim: no duplicate retry")
            # Simulate queue contention separately from the pantry database.
            with sqlite3.connect(path/"events.db") as lock:
                lock.execute("BEGIN EXCLUSIVE")
                try:
                    check(health(q)["state"]=="unavailable","locked queue reports unavailable without mutating it")
                    result,elapsed=post(s,"shopping_add",{"items":[{"name":"Paprika","specification":"1"}]})
                    check(result["added"]==1 and elapsed<1.5,"locked outbox cannot block or roll back shopping write")
                finally:
                    lock.rollback()
            check(count(q)==0,"queue error does not cause inline network fallback")
            with sqlite3.connect(q/"data/evershelf.db") as db:
                check(db.execute("SELECT count(*) FROM shopping_list WHERE lower(name)='paprika'").fetchone()[0]==1,
                      "shopping entry persisted despite outbox error")
            logs="\n".join(f.read_text(errors="replace") for f in (q/"logs").glob("*.log"))
            check("HTTP 503" in logs and "enqueue failed; event dropped" in logs,
                  "503 and enqueue failure logged")
            post(s,"inventory_update",{"id":iid,"quantity":5,"location":"dispensa"})
            check(count(q)==1,"pending post-backup change")
            # A locked outbox must prevent a restore, not leave a stale event behind.
            with sqlite3.connect(path/"events.db") as lock:
                lock.execute("BEGIN EXCLUSIVE")
                try:
                    rejected=restore(s,backupname)
                    check(not rejected["success"] and rejected["error_key"]=="error.backup_restore_failed",
                          "cannot invalidate locked outbox: restore rejected")
                finally:
                    lock.rollback()
            with sqlite3.connect(q/"data/evershelf.db") as db:
                check(db.execute("SELECT quantity FROM inventory WHERE id=?",(iid,)).fetchone()[0]==5,
                      "failed restore leaves latest stock intact")
            check(count(q)==1,"failed invalidation preserves pending event")
            result=restore(s,backupname)
            check(result["success"] and count(q)==0,"valid restore discards pending pre-restore events")
            finish(worker(q))
            check(len(received)==5,"no stale post-backup event delivered")
            with sqlite3.connect(q/"data/evershelf.db") as db:
                check(db.execute("SELECT quantity FROM inventory WHERE id=?",(iid,)).fetchone()[0]==4
                      and db.execute("PRAGMA quick_check").fetchone()[0]=="ok","restored stock and integrity")
            post(s,"inventory_update",{"id":iid,"quantity":6,"location":"dispensa"})
            check(count(q)==1,"new post-restore stock change is queued")
            finish(worker(q))
            check(len(received)==6 and received[-1]["quantity"]==6,"post-restore worker resumes normally")
            # Exercise the queue count and expiration limits without network calls.
            with sqlite3.connect(path/"events.db") as db:
                db.executemany("INSERT INTO events(event,payload,target_hash,created_at) VALUES (?,?,?,?)",
                               [("stock_update","{}","invalid",0)]*500)
            post(s,"inventory_update",{"id":iid,"quantity":7,"location":"dispensa"})
            check(count(q)==500,"full outbox rejects event without rejecting stock change")
            finish(worker(q))
            check(count(q)==450 and len(received)==6,"worker honors 50-event bound and drops expired rows")
            for _ in range(9):
                finish(worker(q))
            check(count(q)==0 and len(received)==6,"bounded queue drains expired backlog without delivery")


        finally:
            if w is not None:
                w.terminate();w.wait()
            if s2:s2.close()
            s.close()
finally:r.shutdown();r.server_close()
