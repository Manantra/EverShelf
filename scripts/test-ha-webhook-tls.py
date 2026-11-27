#!/usr/bin/env python3
"""Real PHP/SQLite shopping and stock writes against disposable HTTP and TLS receivers."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.request import Request, urlopen
import json
import runpy
import shutil
import sqlite3
import ssl
import sys
import subprocess
import tempfile
import threading

ROOT = Path(__file__).resolve().parent.parent
fixture = runpy.run_path(str(ROOT / "scripts/test-api-token-autogen.py"), run_name="qa_fixture")
sandbox, Server = fixture["sandbox"], fixture["Server"]

def openssl(*args):
    subprocess.run(["openssl", *map(str, args)], check=True,
                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

def certificates(p):
    ca = p / "ca.pem"
    key = p / "ca.key"
    openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", 2,
            "-subj", "/CN=EverShelf test CA", "-keyout", key, "-out", ca,
            "-addext", "basicConstraints=critical,CA:TRUE")
    for name, san in (("valid", "IP:127.0.0.1"), ("wrong", "DNS:wrong.invalid")):
        k, csr, cert = (p / (name + ext) for ext in (".key", ".csr", ".pem"))
        openssl("req", "-new", "-newkey", "rsa:2048", "-nodes",
                "-subj", "/CN=" + name, "-keyout", k, "-out", csr)
        ext = p / (name + ".ext")
        ext.write_text("subjectAltName=" + san + "\nextendedKeyUsage=serverAuth\n")
        openssl("x509", "-req", "-in", csr, "-CA", ca, "-CAkey", key,
                "-CAcreateserial", "-days", 2, "-extfile", ext, "-out", cert)
    openssl("req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", 2,
            "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1",
            "-keyout", p/"rogue.key", "-out", p/"rogue.pem")
    return ca

def case(p, name, cert, bundle, expected, response=200, *, async_mode=False):
    name = ("queued-" if async_mode else "direct-") + name
    received = []
    class Handler(BaseHTTPRequestHandler):
        def do_POST(self):
            received.append((self.path, json.loads(self.rfile.read(int(self.headers["Content-Length"])))))
            self.send_response(response)
            self.send_header("Content-Length", "0")
            self.end_headers()
        def log_message(self, *args):
            pass
    class Receiver(ThreadingHTTPServer):
        def handle_error(self, request, client_address):
            # An aborted handshake/connection is normal for a rejected peer.
            if not expected and isinstance(sys.exc_info()[1], (ssl.SSLError, ConnectionResetError)):
                return
            super().handle_error(request, client_address)
    receiver = Receiver(("127.0.0.1", 0), Handler)
    if cert:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(p/(cert+".pem"), p/(cert+".key"))
        receiver.socket = ctx.wrap_socket(receiver.socket, server_side=True)
    worker = threading.Thread(target=receiver.serve_forever, daemon=True)
    worker.start()
    server = None
    try:
        url = ("https" if cert else "http") + "://127.0.0.1:" + str(receiver.server_port)
        q = sandbox(p/name, {"HA_ENABLED":"true", "HA_URL":url,
                    "API_TOKEN":"qa-isolated-token-44", "HA_WEBHOOK_ID":"qa-only", "HA_WEBHOOK_EVENTS":"shopping_add,stock_update",
                    "HA_WEBHOOK_CA_BUNDLE":str(bundle or ""), "LOG_LEVEL":"DEBUG",
                    "HA_WEBHOOK_ASYNC":"true" if async_mode else "false"})
        for file in ("api/index.php", "api/lib/ha_webhook_outbox.php", "api/cron_ha_webhooks.php"):
            shutil.copy2(ROOT/file, q/file)
        server = Server(q)
        def post(action, payload):
            req = Request(server.url+action, data=json.dumps(payload).encode(),
                          headers={"Content-Type":"application/json",
                                   "X-API-Token":"qa-isolated-token-44",
                                   "X-EverShelf-Request":"1"}, method="POST")
            with urlopen(req, timeout=16) as r:
                result=json.load(r)
            assert result.get("success") is True, (name,action,result)
            return result

        added=post("shopping_add", {"items":[{"name":"QA TLS Quinoa", "specification":"1"}]})
        assert added.get("added") == 1, (name,added)
        with sqlite3.connect(q/"data/evershelf.db") as db:
            assert db.execute("SELECT count(*) FROM shopping_list").fetchone()[0] == 1, name
        product=post("product_save", {"name":"QA TLS Beans", "name_user_set":1,
                      "lang":"de", "category":"conserve", "unit":"conf",
                      "default_quantity":1, "package_unit":"conf"})
        pid=int(product["id"])
        post("inventory_add", {"product_id":pid,"quantity":2,"unit":"conf","location":"dispensa"})
        with sqlite3.connect(q/"data/evershelf.db") as db:
            iid=db.execute("SELECT id FROM inventory WHERE product_id=?", (pid,)).fetchone()[0]
        post("inventory_update", {"id":iid,"quantity":4,"location":"dispensa"})
        with sqlite3.connect(q/"data/evershelf.db") as db:
            assert db.execute("SELECT quantity FROM inventory WHERE id=?", (iid,)).fetchone()[0] == 4, name
            assert db.execute("PRAGMA quick_check").fetchone()[0] == "ok", name
        if async_mode:
            assert not received, (name,"network send before worker")
            subprocess.run([fixture["PHP"], str(q/"api/cron_ha_webhooks.php")], cwd=q, check=True, timeout=20)
        assert len(received) == 2*expected, (name,"received",len(received),"expected",2*expected)
        if expected:
            assert [body["event"] for _,body in received] == ["shopping_add","stock_update"], name
            assert all(path == "/api/webhook/qa-only" and body["source"]=="evershelf"
                       for path,body in received), name
            assert received[1][1]["quantity"] == 4, name
        logs = "\n".join(f.read_text(errors="replace") for f in (q/"logs").glob("*.log"))
        for event in ("shopping_add","stock_update"):
            prefix = "_fireHaWebhook["+event+"]: "
            if expected and response == 200:
                assert any("[DEBUG]" in line and prefix+"HTTP 200" in line
                           for line in logs.splitlines()), (name,event)
            elif response == 503:
                assert any("[WARN" in line and prefix+"HTTP 503" in line
                           for line in logs.splitlines()), (name,event)
            else:
                assert any("[WARN" in line and prefix in line
                           for line in logs.splitlines()), (name,event)
                assert not any("[DEBUG]" in line and prefix in line
                               for line in logs.splitlines()), (name,event)
        print("PASS", name, "deliveries =", 2*expected, "SQLite shopping + stock writes retained", flush=True)
    finally:
        if server:
            server.close()
        receiver.shutdown()
        receiver.server_close()
        worker.join(timeout=3)

if __name__ == "__main__":
    with tempfile.TemporaryDirectory(prefix="evershelf-ha-tls-") as tmp:
        p=Path(tmp)
        ca=certificates(p)
        bad=p/"invalid.pem"
        bad.write_text("not a certificate\n")
        for async_mode in (False,True):
            def run(*args):
                case(p,*args,async_mode=async_mode)
            run("untrusted-default","rogue",None,0)
            run("trusted-private-ca","valid",ca,1)
            run("untrusted-with-ca","rogue",ca,0)
            run("wrong-hostname","wrong",ca,0)
            run("missing-bundle","valid",p/"missing.pem",0)
            run("invalid-bundle","valid",bad,0)
            run("http-local",None,None,1)
            run("http-ignores-ca-path",None,p/"missing.pem",1)
            run("https-503","valid",ca,1,503)
