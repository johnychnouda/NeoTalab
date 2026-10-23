# Stand-in for the Whish Pay API (labs only; the app ignores WHISH_BASE_URL in production).
#   POST /payment/whish            create a payment link        → data.collectUrl
#   POST /payment/collect/status   status for externalId+amount → data.collectStatus (success only for the exact amount)
#   GET  /payment/account/balance  credentials check
#   GET  /pay/<externalId>?result=success|failure[&amount=X]   "the customer pays", then Whish calls the app back
import json, os, sys, threading, time, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

LOG = sys.argv[1]
STATE = os.path.join(os.path.dirname(os.path.abspath(LOG)), "whish_state.json")
lock = threading.Lock()


def load():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}


def save(s):
    json.dump(s, open(STATE, "w"), indent=1)


class H(BaseHTTPRequestHandler):
    def _rec(self, body):
        with open(LOG, "a") as f:
            f.write(json.dumps({"t": time.time(), "method": self.command, "path": self.path, "body": body,
                                "channel": self.headers.get("channel")}) + "\n")

    def _json(self, out, code=200):
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(json.dumps(out).encode())

    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = json.loads(self.rfile.read(n) or b"{}")
        self._rec(body)
        if self.headers.get("secret") != "lab-whish-secret":
            return self._json({"status": False, "code": "unauthorized"}, 401)
        path = urlparse(self.path).path
        with lock:
            s = load()
            if path.endswith("/payment/whish"):
                ext = str(body["externalId"])
                s[ext] = {"amount": float(body["amount"]), "currency": body["currency"], "success": body["successCallbackUrl"],
                          "failure": body["failureCallbackUrl"], "status": "pending", "paid": None}
                save(s)
                return self._json({"status": True, "data": {"collectUrl": f"http://127.0.0.1:8029/pay/{ext}"}})
            if path.endswith("/payment/collect/status"):
                p = s.get(str(body.get("externalId")))
                if not p:
                    return self._json({"status": True, "data": {"collectStatus": "pending"}})
                exact = p["paid"] is not None and abs(float(body["amount"]) - p["paid"]) < 0.005 and body["currency"] == p["currency"]
                status = "success" if (p["status"] == "success" and exact) else ("failed" if p["status"] == "failed" else "pending")
                return self._json({"status": True, "data": {"collectStatus": status}})
        self._json({"status": False}, 404)

    def do_GET(self):
        self._rec(None)
        u = urlparse(self.path); q = parse_qs(u.query)
        if u.path.endswith("/payment/account/balance"):
            ok = self.headers.get("secret") == "lab-whish-secret"
            return self._json({"status": ok, "data": {"balance": 100}} if ok else {"status": False}, 200 if ok else 401)
        if u.path.startswith("/pay/"):
            ext = u.path.split("/")[-1]
            result = (q.get("result") or ["success"])[0]
            with lock:
                s = load(); p = s.get(ext)
                if not p:
                    return self._json({"error": "unknown link"}, 404)
                p["status"] = "success" if result == "success" else "failed"
                p["paid"] = float((q.get("amount") or [p["amount"]])[0]) if result == "success" else None
                save(s)
            cb = p["success"] if result == "success" else p["failure"]
            try:
                with urllib.request.urlopen(cb, timeout=30) as r:
                    code = r.status
            except urllib.error.HTTPError as e:
                code = e.code
            except Exception as e:
                code = str(e)
            return self._json({"paid": result == "success", "amount": p["paid"], "callback": code})
        self._json({"status": False}, 404)

    def log_message(self, *a):
        pass


ThreadingHTTPServer(("127.0.0.1", 8029), H).serve_forever()
