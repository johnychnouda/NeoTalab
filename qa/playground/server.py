#!/usr/bin/env python3
"""Local NeoTalab WhatsApp playground UI backend.
Talks only to the QA lab (fake Meta Graph + lab SQLite). Never production.
"""
from __future__ import annotations

import json
import os
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from lib import (  # noqa: E402
    ENV,
    GRAPHLOG,
    LABSH,
    STATE,
    open_all_day,
    outbox,
    put_in_cart,
    settle,
    state,
    texts,
    tj,
    wa_echo,
    wa_msg,
    wa_post,
)

import subprocess
import urllib.error
import urllib.request

LAB_DIR = Path(STATE).resolve().parent
QUEUE_PID = LAB_DIR / "queue.pid"
QUEUE_LOG = LAB_DIR / "queue.log"
API_PID = LAB_DIR / "api.pid"
API_LOG = LAB_DIR / "api.log"
MOCK_PID = LAB_DIR / "mock_graph.pid"
QA_DIR = ROOT
API_UP = "http://127.0.0.1:8011/up"
MOCK_UP = "http://127.0.0.1:8019/"


def _pid_alive(path: Path) -> bool:
    try:
        if not path.is_file():
            return False
        os.kill(int(path.read_text().strip()), 0)
        return True
    except Exception:
        return False


def _http_ok(url: str, timeout: float = 0.6) -> bool:
    try:
        with urllib.request.urlopen(url, timeout=timeout) as r:
            return 200 <= r.status < 500
    except Exception:
        return False


def _spawn(cmd: list[str], pid_file: Path, log_file: Path) -> bool:
    try:
        with open(log_file, "a") as log:
            proc = subprocess.Popen(cmd, stdout=log, stderr=log, start_new_session=True, cwd=str(QA_DIR))
        pid_file.write_text(str(proc.pid))
        return True
    except Exception:
        return False


def ensure_lab() -> dict:
    """Restart mock Graph, lab API, and queue if anything is down."""
    status = {"mock": False, "api": False, "queue": False}

    status["mock"] = _http_ok(MOCK_UP)
    if not status["mock"]:
        _spawn(
            [sys.executable, str(QA_DIR / "mock_graph.py"), str(Path(GRAPHLOG))],
            MOCK_PID,
            LAB_DIR / "mock_graph.log",
        )
        for _ in range(15):
            time.sleep(0.15)
            if _http_ok(MOCK_UP):
                status["mock"] = True
                break

    status["api"] = _http_ok(API_UP)
    if not status["api"]:
        _spawn(
            [LABSH, "php", "artisan", "serve", "--host=127.0.0.1", "--port=8011"],
            API_PID,
            API_LOG,
        )
        for _ in range(40):
            time.sleep(0.2)
            if _http_ok(API_UP):
                status["api"] = True
                break

    status["queue"] = _pid_alive(QUEUE_PID)
    if not status["queue"]:
        _spawn(
            [LABSH, "php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--timeout=90"],
            QUEUE_PID,
            QUEUE_LOG,
        )
        time.sleep(0.4)
        status["queue"] = _pid_alive(QUEUE_PID)

    status["ok"] = bool(status["mock"] and status["api"] and status["queue"])
    return status


def ensure_queue() -> bool:
    return bool(ensure_lab().get("queue"))

HOST = os.environ.get("PLAYGROUND_HOST", "127.0.0.1")
PORT = int(os.environ.get("PLAYGROUND_PORT", "8025"))
STATIC = Path(__file__).resolve().parent


# Each profile maps to a seeded lab shop and documents what production path it covers.
SCENARIO_META = {
    "rest": {
        "label": "Restaurant — manual accept + driver + Whish",
        "blurb": "Production restaurant path with auto_accept OFF. Sizes/extras, delivery+pickup, Whish, LBP, driver dispatch, staff-check (Arak).",
        "shop_key": "rest",
        "with_driver": True,
        "customer_hint": "hi → add 2 Falafel → Large / Cheese → done → Delivery → pin → Cash or Whish → Confirm → shop ✅ → driver start → buttons → rate",
        "customer_chips": ["hi", "menu", "add 2 Falafel", "Large", "with Cheese", "done", "where's my order?", "cancel my order", "human", "add 1 Arak Bottle"],
        "shop_hints": ["✅", "❌", "ready", "out", "done", "delay", "refunded", "⏸", "▶️"],
        "driver_hints": ["start", "stop"],
        "covers": ["manual_accept", "driver", "whish", "lbp", "pickup", "delivery", "staff_check", "rating", "track", "cancel", "handoff"],
    },
    "pharm": {
        "label": "Bakery — pickup + auto-accept",
        "blurb": "Production default: auto_accept ON. Pickup only. Shop uses ready → done (no driver).",
        "shop_key": "pharm",
        "with_driver": False,
        "customer_hint": "hi → Chocolate Croissant → done → Pickup → cash → Confirm (auto accepted) → shop ready → done → rate",
        "customer_chips": ["hi", "menu", "Chocolate Croissant", "Knefeh", "done", "where's my order?", "human"],
        "shop_hints": ["✅", "❌", "ready", "done", "delay", "⏸", "▶️"],
        "driver_hints": [],
        "covers": ["auto_accept", "pickup", "no_driver", "rating"],
    },
    "grocer": {
        "label": "Grocery — delivery / self-deliver or driver",
        "blurb": "Auto-accept ON. Delivery zones + kg items. Leave driver stopped for shop out/done, or start driver for dispatch.",
        "shop_key": "grocer",
        "with_driver": True,
        "customer_hint": "hi → Labneh / 0.5 Tomatoes → done → Delivery → pin → cash → Confirm → shop ready → out → done (or driver start)",
        "customer_chips": ["hi", "menu", "Labneh", "half kilo Tomatoes", "done", "where's my order?", "cancel my order"],
        "shop_hints": ["✅", "❌", "ready", "out", "done", "delay", "refunded", "⏸", "▶️"],
        "driver_hints": ["start", "stop"],
        "covers": ["auto_accept", "delivery", "self_delivery", "kg", "driver_optional", "whish"],
    },
    "retail": {
        "label": "Retail — days timing",
        "blurb": "Online shop / retail. Timing in working days. Color variants. Delivery or pickup.",
        "shop_key": "retail",
        "with_driver": False,
        "customer_hint": "hi → T-Shirt → Black → done → Delivery → pin → Confirm → wait for day windows → shop ready/out/done",
        "customer_chips": ["hi", "menu", "T-Shirt", "Black", "done", "where's my order?"],
        "shop_hints": ["✅", "❌", "ready", "out", "done", "delay", "⏸", "▶️"],
        "driver_hints": [],
        "covers": ["retail_days", "variants", "auto_accept", "delivery"],
    },
    "whole": {
        "label": "Butcher / produce — kg + hours",
        "blurb": "Produce/butcher style. Weight items, delivery in hours.",
        "shop_key": "whole",
        "with_driver": False,
        "customer_hint": "hi → 1.5 Minced Beef → done → Delivery → pin → cash → Confirm → shop ready → out → done",
        "customer_chips": ["hi", "menu", "1.5 Minced Beef", "done", "where's my order?"],
        "shop_hints": ["✅", "❌", "ready", "out", "done", "delay", "⏸", "▶️"],
        "driver_hints": [],
        "covers": ["kg", "hours_timing", "delivery", "self_delivery", "auto_accept"],
    },
}

BEIRUT_PIN = [33.8938, 35.5018]
OUT_OF_ZONE_PIN = [34.4367, 35.8362]  # Tripoli-ish — outside Beirut circles


def scenarios():
    s = state()
    shops = s.get("shops") or {}
    out = []
    for key, meta in SCENARIO_META.items():
        shop_key = meta.get("shop_key", key)
        sh = shops.get(shop_key)
        if not sh:
            continue
        driver_phone = None
        if meta["with_driver"]:
            try:
                driver_phone = tj(
                    'echo json_encode(App\\Models\\Driver::withoutGlobalScopes()'
                    '->where("merchant_id","' + sh["id"] + '")'
                    '->where("role","driver")->orderBy("created_at")->value("whatsapp_number"));'
                )
            except Exception:
                driver_phone = "96171222001"
            if isinstance(driver_phone, str):
                driver_phone = driver_phone.lstrip("+")
        out.append({
            "key": key,
            "shop_key": shop_key,
            "id": sh["id"],
            "name": sh.get("name") or key,
            "phone_id": sh["phone_id"],
            "wa": sh.get("wa"),
            "driver_phone": driver_phone,
            "p1": sh.get("p1"),
            "variant": sh.get("variant"),
            "mod": sh.get("mod"),
            "check_product": sh.get("check_product"),
            "shirt": sh.get("shirt"),
            "color_variant": sh.get("color_variant"),
            **{k: v for k, v in meta.items() if k != "shop_key"},
        })
    return out


def coverage():
    s = state()
    return s.get("playground_coverage") or {
        "customer": [], "shop": [], "driver": [], "types": [],
        "note": "Run python3 qa/seed_playground.py for the full checklist.",
    }


def format_outgoing(rows):
    msgs = []
    for r in rows:
        b = r.get("body") or {}
        to = str(b.get("to") or "").lstrip("+")
        t = b.get("type")
        buttons = []
        lists = []
        text = ""
        if t == "text":
            text = (b.get("text") or {}).get("body") or ""
        elif t == "interactive":
            i = b.get("interactive") or {}
            text = (i.get("body") or {}).get("text") or ""
            act = i.get("action") or {}
            for x in act.get("buttons") or []:
                reply = x.get("reply") or {}
                buttons.append({"id": reply.get("id"), "title": reply.get("title")})
            for sec in act.get("sections") or []:
                for row in sec.get("rows") or []:
                    lists.append({"id": row.get("id"), "title": row.get("title")})
        elif t == "template":
            text = "TEMPLATE " + ((b.get("template") or {}).get("name") or "?")
        else:
            text = json.dumps(b)[:400]
        msgs.append({
            "t": r.get("t"),
            "to": to,
            "type": t,
            "text": text.replace("\u2019", "'"),
            "buttons": buttons,
            "lists": lists,
        })
    return msgs


def session_phones(body):
    run = body.get("run") or str(int(time.time()))[-6:]
    customer = str(body.get("customer") or f"96177{run}").lstrip("+")
    return run, customer


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def _json(self, data, code=200):
        raw = json.dumps(data, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _read_json(self):
        n = int(self.headers.get("Content-Length") or 0)
        raw = self.rfile.read(n) if n else b"{}"
        try:
            return json.loads(raw or b"{}")
        except Exception:
            return {}

    def do_OPTIONS(self):
        self.send_response(204)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()

    def do_GET(self):
        path = urlparse(self.path).path
        if path in ("/", "/index.html"):
            return self._file(STATIC / "index.html", "text/html; charset=utf-8")
        if path == "/app.js":
            return self._file(STATIC / "app.js", "application/javascript; charset=utf-8")
        if path == "/app.css":
            return self._file(STATIC / "app.css", "text/css; charset=utf-8")
        if path == "/api/health":
            lab = ensure_lab()
            return self._json({
                "ok": lab.get("ok", False),
                "lab": ENV.get("DB_DATABASE"),
                "api": os.environ.get("QA_API", "http://127.0.0.1:8011/api/v1"),
                "graphlog": GRAPHLOG,
                "state": STATE,
                "queue": lab.get("queue"),
                "mock": lab.get("mock"),
                "api_up": lab.get("api"),
            })
        if path == "/api/scenarios":
            try:
                return self._json({
                    "scenarios": scenarios(),
                    "pins": {"beirut": BEIRUT_PIN, "out_of_zone": OUT_OF_ZONE_PIN},
                    "coverage": coverage(),
                })
            except Exception as e:
                return self._json({"error": str(e), "hint": "Run qa/setup_lab.sh then s01_setup.py and s02_catalog.py"}, 503)
        if path == "/api/coverage":
            return self._json({"coverage": coverage()})
        if path == "/api/inbox":
            qs = parse_qs(urlparse(self.path).query)
            since = float((qs.get("since") or ["0"])[0] or 0)
            return self._json({"messages": format_outgoing(outbox(since=since)), "now": time.time()})
        self._json({"error": "not found"}, 404)

    def _file(self, path: Path, ctype: str):
        if not path.is_file():
            return self._json({"error": "missing " + path.name}, 404)
        data = path.read_bytes()
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_POST(self):
        path = urlparse(self.path).path
        body = self._read_json()
        try:
            if path in ("/api/prepare", "/api/customer", "/api/shop", "/api/driver", "/api/tap", "/api/seed-cart", "/api/mode"):
                lab = ensure_lab()
                if not lab.get("ok"):
                    return self._json({
                        "error": "Lab stack not ready (api/mock/queue). Wait a second and retry, or run ./qa/start_playground.sh",
                        "lab": lab,
                    }, 503)
            if path == "/api/prepare":
                open_all_day()
                return self._json({"ok": True})
            if path == "/api/customer":
                return self._customer(body)
            if path == "/api/shop":
                return self._shop(body)
            if path == "/api/driver":
                return self._driver(body)
            if path == "/api/tap":
                return self._tap(body)
            if path == "/api/seed-cart":
                return self._seed_cart(body)
            if path == "/api/mode":
                return self._mode(body)
        except Exception as e:
            return self._json({"error": str(e)}, 500)
        self._json({"error": "not found"}, 404)

    def _customer(self, body):
        phone_id = body["phone_id"]
        _, customer = session_phones(body)
        quiet = float(body.get("quiet") or 1.4)
        cap = float(body.get("cap") or 45)
        name = body.get("name") or "Playground Customer"

        # Special: WhatsApp call event (not a normal message).
        if body.get("event") == "call":
            payload = {"object": "whatsapp_business_account", "entry": [{"id": "WABA", "changes": [{"field": "calls", "value": {
                "messaging_product": "whatsapp",
                "metadata": {"display_phone_number": "961", "phone_number_id": phone_id},
                "calls": [{"id": "wacid.PG" + customer[-6:], "from": customer, "to": "961",
                           "event": "connect", "timestamp": str(int(time.time())), "direction": "USER_INITIATED"}],
            }}]}]}
            t0 = time.time()
            code, _ = wa_post(payload)
            rows = settle(t0, quiet=quiet, cap=cap)
            mine = [r for r in rows if str((r.get("body") or {}).get("to") or "").lstrip("+") == customer]
            return self._json({"ok": code == 200, "code": code, "customer": customer, "since": t0,
                               "messages": format_outgoing(mine), "flat": texts(mine)})

        kw = {"name": name}
        if body.get("location"):
            kw["location"] = tuple(body["location"])
        elif body.get("button"):
            kw["button"] = body["button"]
        elif body.get("list_id"):
            kw["list_id"] = body["list_id"]
        elif body.get("media") == "photo":
            kw["kind"] = {"type": "image", "image": {"id": "PG_PHOTO", "mime_type": "image/jpeg"}}
        elif body.get("media") == "voice":
            # Missing media file → polite voice_failed (still a real production path).
            kw["kind"] = {"type": "audio", "audio": {"id": "PG_VOICE_MISSING", "mime_type": "audio/ogg; codecs=opus", "voice": True}}
        else:
            kw["text"] = body.get("text") or ""

        code, mid, t0 = wa_msg(phone_id, customer, **kw)
        rows = settle(t0, quiet=quiet, cap=cap)
        mine = [r for r in rows if str((r.get("body") or {}).get("to") or "").lstrip("+") == customer]
        return self._json({
            "ok": code == 200,
            "code": code,
            "mid": mid,
            "customer": customer,
            "since": t0,
            "messages": format_outgoing(mine),
            "flat": texts(mine),
        })

    def _shop(self, body):
        phone_id = body["phone_id"]
        customer = str(body.get("customer") or "").lstrip("+")
        text = body.get("text") or ""
        code, t0 = wa_echo(phone_id, customer, text)
        rows = settle(t0, quiet=float(body.get("quiet") or 1.2), cap=float(body.get("cap") or 25))
        driver = str(body.get("driver") or "").lstrip("+")
        allow = {customer}
        if driver:
            allow.add(driver)
        mine = [r for r in rows if str((r.get("body") or {}).get("to") or "").lstrip("+") in allow]
        return self._json({
            "ok": code == 200,
            "code": code,
            "customer": customer,
            "since": t0,
            "messages": format_outgoing(mine),
            "flat": texts(mine),
        })

    def _driver(self, body):
        phone_id = body["phone_id"]
        driver = str(body.get("driver") or "").lstrip("+")
        kw = {}
        if body.get("button"):
            kw["button"] = body["button"]
        else:
            kw["text"] = body.get("text") or ""
        code, mid, t0 = wa_msg(phone_id, driver, name=body.get("name") or "Driver", **kw)
        rows = settle(t0, quiet=float(body.get("quiet") or 1.2), cap=float(body.get("cap") or 25))
        mine = [r for r in rows if str((r.get("body") or {}).get("to") or "").lstrip("+") == driver]
        return self._json({
            "ok": code == 200,
            "code": code,
            "mid": mid,
            "driver": driver,
            "since": t0,
            "messages": format_outgoing(mine),
            "flat": texts(mine),
        })

    def _tap(self, body):
        """Customer taps a button/list id from the last bot message."""
        body = {**body, "button": body.get("id")} if not body.get("list_id") else body
        if body.get("list_id"):
            body.pop("button", None)
        return self._customer(body)

    def _seed_cart(self, body):
        """Skip Fake-AI size/extra friction: put a known item in the cart like the assistant would."""
        merchant_id = body["merchant_id"]
        customer = str(body.get("customer") or "").lstrip("+")
        product_id = body["product_id"]
        qty = float(body.get("qty") or 1)
        variant = body.get("variant_id")
        mods = body.get("modifier_ids") or []
        put_in_cart(merchant_id, customer, product_id, qty=qty, variant=variant, mods=tuple(mods))
        return self._json({"ok": True, "customer": customer, "product_id": product_id})

    def _mode(self, body):
        """Set shop mode: auto | busy | manual | closed (production Settings → Orders)."""
        merchant_id = body["merchant_id"]
        mode = body.get("mode") or "auto"
        tj(
            '$m=App\\Models\\Merchant::withoutGlobalScopes()->find("' + merchant_id + '");'
            '$s=$m->settings ?? []; $s["mode"]="' + mode + '";'
            '$m->forceFill(["settings"=>$s])->save(); echo json_encode($s["mode"]);'
        )
        return self._json({"ok": True, "mode": mode})


def main():
    if not Path(STATE).is_file():
        print(f"Missing {STATE}. Run: bash qa/setup_lab.sh && python3 qa/s01_setup.py && python3 qa/s02_catalog.py", file=sys.stderr)
        sys.exit(1)
    print(f"NeoTalab playground → http://{HOST}:{PORT}/  (lab only)")
    ThreadingHTTPServer((HOST, PORT), Handler).serve_forever()


if __name__ == "__main__":
    main()
