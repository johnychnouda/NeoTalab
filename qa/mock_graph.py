# Stand-in for Meta's Graph API: records every outgoing WhatsApp call, answers like Meta.
import json, time, uuid, sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
LOG = sys.argv[1]
class H(BaseHTTPRequestHandler):
    def _rec(self, body):
        with open(LOG, "a") as f:
            f.write(json.dumps({"t": time.time(), "method": self.command, "path": self.path, "body": body}) + "\n")
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0); raw = self.rfile.read(n)
        try: body = json.loads(raw or b"{}")
        except Exception: body = {"raw": raw[:200].decode("latin1")}
        self._rec(body)
        out = {"messaging_product": "whatsapp", "messages": [{"id": "wamid.LAB" + uuid.uuid4().hex}], "success": True, "id": "lab"}
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(json.dumps(out).encode())
    def do_GET(self):
        self._rec(None)
        out = {"data": [], "id": "lab", "verified_name": "Lab", "display_phone_number": "+961 70 000000", "quality_rating": "GREEN"}
        self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(json.dumps(out).encode())
    do_DELETE = do_GET
    def log_message(self, *a): pass
ThreadingHTTPServer(("127.0.0.1", 8019), H).serve_forever()
