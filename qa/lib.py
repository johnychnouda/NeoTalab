"""QA harness for the NeoTalab lab (API on 127.0.0.1:8011, mock Graph on 8019, lab SQLite DB).
Never points at production. Credentials come from lab.env and are never printed."""
import hashlib, hmac, json, os, subprocess, sys, time, uuid, urllib.request, urllib.error

LAB = os.environ.get("QA_LAB") or os.path.join(os.path.dirname(os.path.abspath(__file__)), ".lab")
QA = os.path.dirname(os.path.abspath(__file__))
API = os.environ.get("QA_API", "http://127.0.0.1:8011/api/v1")
ENV = dict(l.strip().split("=", 1) for l in open(f"{LAB}/lab.env") if "=" in l and not l.startswith("#"))
assert API.startswith("http://127.0.0.1:") and ((ENV["DB_CONNECTION"] == "sqlite" and ENV["DB_DATABASE"].startswith(LAB)) or (ENV["DB_CONNECTION"] == "mysql" and ENV["DB_DATABASE"].startswith("neotalab_qa") and ENV.get("DB_HOST") == "127.0.0.1")), "refusing: not the lab"
STATE = f"{LAB}/state.json"  # holds lab logins: lives in the git-ignored lab folder
RESULTS = f"{LAB}/results.jsonl"


def state():
    return json.load(open(STATE)) if os.path.exists(STATE) else {}


def save_state(s):
    json.dump(s, open(STATE, "w"), indent=1)


_last = {}


def api(method, path, token=None, body=None, raw=None, headers=None):
    # Stay under the API's own limit (120 requests/minute per user) so 429s mean something.
    if token:
        wait = _last.get(token, 0) + 0.51 - time.time()
        if wait > 0:
            time.sleep(wait)
        _last[token] = time.time()
    url = path if path.startswith("http") else API + path
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header("Accept", "application/json")
    if data is not None:
        req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            txt = r.read().decode()
            code = r.status
    except urllib.error.HTTPError as e:
        txt = e.read().decode()
        code = e.code
    try:
        return code, json.loads(txt) if txt else None
    except ValueError:
        return code, txt


def login(email, password, retries=6):
    for i in range(retries):
        code, b = api("POST", "/auth/login", body={"email": email, "password": password})
        if code == 429:
            time.sleep(15)
            continue
        return code, b
    return code, b


def tinker(php):
    """Run PHP in the lab app (for setup and read-only DB checks)."""
    out = subprocess.run([f"{LAB}/lab.sh", "php", "artisan", "tinker", "--execute", php],
                         capture_output=True, text=True, timeout=120)
    return out.stdout.strip() + (("\nERR " + out.stderr.strip()[-800:]) if out.returncode else "")


def tj(php):
    """tinker returning JSON: php must echo json_encode(...)."""
    o = tinker(php)
    try:
        return json.loads(o.splitlines()[-1])
    except Exception:
        raise RuntimeError(o)


# ── WhatsApp (fake Meta) ─────────────────────────────────────
def _sign(raw):
    return "sha256=" + hmac.new(ENV["WHATSAPP_APP_SECRET"].encode(), raw, hashlib.sha256).hexdigest()


def wa_post(payload, sign=True, sig=None):
    raw = json.dumps(payload).encode()
    h = {"X-Hub-Signature-256": sig if sig is not None else (_sign(raw) if sign else "")}
    return api("POST", "/webhooks/whatsapp", raw=raw, headers=h)


def wa_msg(phone_id, frm, text=None, button=None, list_id=None, location=None, mid=None, name="Customer", kind=None, extra=None):
    mid = mid or "wamid.QA" + uuid.uuid4().hex
    m = {"from": frm, "id": mid, "timestamp": str(int(time.time()))}
    if text is not None:
        m.update(type="text", text={"body": text})
    elif button is not None:
        m.update(type="interactive", interactive={"type": "button_reply", "button_reply": {"id": button, "title": button}})
    elif list_id is not None:
        m.update(type="interactive", interactive={"type": "list_reply", "list_reply": {"id": list_id, "title": list_id}})
    elif location is not None:
        m.update(type="location", location={"latitude": location[0], "longitude": location[1]})
    if kind:
        m.update(kind)
    if extra:
        m.update(extra)
    payload = {"object": "whatsapp_business_account", "entry": [{"id": "WABA", "changes": [{"field": "messages", "value": {
        "messaging_product": "whatsapp", "metadata": {"display_phone_number": "961", "phone_number_id": phone_id},
        "contacts": [{"profile": {"name": name}, "wa_id": frm}], "messages": [m]}}]}]}
    t0 = time.time()
    code, b = wa_post(payload)
    return code, mid, t0


def wa_echo(phone_id, to, text):
    """The shop phone typing in a customer's chat (coexistence echo)."""
    payload = {"object": "whatsapp_business_account", "entry": [{"id": "WABA", "changes": [{"field": "smb_message_echoes", "value": {
        "messaging_product": "whatsapp", "metadata": {"display_phone_number": "961", "phone_number_id": phone_id},
        "message_echoes": [{"from": "961", "to": to, "id": "wamid.ECHO" + uuid.uuid4().hex, "timestamp": str(int(time.time())), "type": "text", "text": {"body": text}}]}}]}]}
    t0 = time.time()
    return wa_post(payload)[0], t0


def outbox(to=None, since=0):
    out = []
    for line in open(f"{LAB}/graph.jsonl"):
        r = json.loads(line)
        if r["t"] < since or r["method"] != "POST" or not isinstance(r["body"], dict):
            continue
        if to and r["body"].get("to") != to:
            continue
        out.append(r)
    return out


def texts(msgs):
    """Flatten outgoing messages to readable strings."""
    res = []
    for r in msgs:
        b = r["body"]
        t = b.get("type")
        if t == "text":
            res.append(b["text"]["body"])
        elif t == "interactive":
            i = b["interactive"]
            s = (i.get("body") or {}).get("text", "")
            act = i.get("action", {})
            btns = [x["reply"]["id"] + "=" + x["reply"]["title"] for x in act.get("buttons", [])]
            rows = [row["id"] for sec in act.get("sections", []) for row in sec.get("rows", [])]
            res.append(s + (" [BTN " + " | ".join(btns) + "]" if btns else "") + (" [LIST " + ",".join(rows) + "]" if rows else ""))
        elif t == "template":
            res.append("TEMPLATE " + b["template"]["name"] + " " + json.dumps(b["template"].get("components", []))[:400])
        else:
            res.append(json.dumps(b)[:300])
    # The newer model writes curly apostrophes ("can’t"); the checks look for plain ones.
    return [x.replace("\u2019", "'") for x in res]


# ── results ──────────────────────────────────────────────────
_suite = {"name": "?"}


def suite(name):
    _suite["name"] = name
    print(f"\n=== {name} ===")


def check(name, ok, detail=""):
    rec = {"suite": _suite["name"], "check": name, "ok": bool(ok), "detail": str(detail)[:500], "t": time.strftime("%H:%M:%S")}
    with open(RESULTS, "a") as f:
        f.write(json.dumps(rec, ensure_ascii=False) + "\n")
    print(("PASS " if ok else "FAIL ") + name + ("" if ok else f"  -> {str(detail)[:300]}"))
    return ok


def settle(t0, quiet=1.2, cap=8.0):
    """Wait until the lab's queue has gone quiet (no new outgoing WhatsApp for `quiet` seconds)."""
    last_n, last_change = -1, time.time()
    while time.time() - t0 < cap:
        n = len(outbox(since=t0))
        if n != last_n:
            last_n, last_change = n, time.time()
        elif time.time() - last_change >= quiet:
            break
        time.sleep(0.15)
    return outbox(since=t0)


def wait_reply(t0, to, quiet=2.5, cap=60.0):
    """Like settle(), but first waits for at least one message to `to` (the real AI is slow)."""
    to = to.lstrip("+")
    while time.time() - t0 < cap and not [x for x in outbox(since=t0) if x["body"].get("to", "").lstrip("+") == to]:
        time.sleep(0.3)
    return settle(t0, quiet=quiet, cap=cap)
