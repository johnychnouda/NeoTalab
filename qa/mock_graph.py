# Stand-in for Meta's Graph API: records every outgoing WhatsApp call, answers like Meta.
# Voice notes: put files in <log dir>/media/<media id>.<ext>; GET /<version>/<media id> returns their
# link (like Meta), and GET /media/<file> serves the audio.
import json, mimetypes, os, sys, time, uuid

PORT = int(os.environ.get("MOCK_GRAPH_PORT", "8019"))
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOG = sys.argv[1]
MEDIA = os.path.join(os.path.dirname(os.path.abspath(LOG)), "media")


def media_file(media_id):
    if not os.path.isdir(MEDIA):
        return None
    for name in os.listdir(MEDIA):
        if os.path.splitext(name)[0] == media_id:
            return os.path.join(MEDIA, name)
    return None


class H(BaseHTTPRequestHandler):
    def _rec(self, body):
        with open(LOG, "a") as f:
            f.write(json.dumps({"t": time.time(), "method": self.command, "path": self.path, "body": body}) + "\n")

    def _json(self, out, code=200):
        self.send_response(code); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(json.dumps(out).encode())

    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0); raw = self.rfile.read(n)
        try:
            body = json.loads(raw or b"{}")
        except Exception:
            body = {"raw": raw[:200].decode("latin1")}
        self._rec(body)
        self._json({"messaging_product": "whatsapp", "messages": [{"id": "wamid.LAB" + uuid.uuid4().hex}], "success": True, "id": "lab"})

    def do_GET(self):
        self._rec(None)
        path = self.path.split("?")[0]
        if path.startswith("/media/"):
            f = os.path.join(MEDIA, os.path.basename(path))
            if not os.path.isfile(f):
                return self._json({"error": "not found"}, 404)
            data = open(f, "rb").read()
            self.send_response(200); self.send_header("Content-Type", mimetypes.guess_type(f)[0] or "audio/ogg"); self.end_headers(); self.wfile.write(data)
            return
        media_id = path.rstrip("/").split("/")[-1]
        f = media_file(media_id)
        if f:
            mime = "audio/ogg" if f.endswith(".ogg") else (mimetypes.guess_type(f)[0] or "application/octet-stream")
            return self._json({"url": f"http://127.0.0.1:{PORT}/media/{os.path.basename(f)}", "mime_type": mime, "file_size": os.path.getsize(f), "id": media_id})
        self._json({"data": [], "id": "lab", "verified_name": "Lab", "display_phone_number": "+961 70 000000", "quality_rating": "GREEN"})

    do_DELETE = do_GET

    def log_message(self, *a):
        pass


ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()
