"""Capacity test against a temporary Railway copy of the scale lab (never production).
Customers write through the signed WhatsApp webhook, owners open dashboard pages, on a loop, at several levels of
virtual users. Reports requests/s, errors, latency per call type, how long the queue took to answer every message
(time to the first reply per customer, from the database) and how many replies never came.
usage: s45_railway_load.py LEVELS_CSV SECONDS   e.g. 25,50,100 60
Needs qa/.lab/scale/{plan,tokens}.json (from the scale lab) and RW_* in the file named by RW_ENV (database proxy)."""
import concurrent.futures as cf, hashlib, hmac, json, os, random, subprocess, sys, time, urllib.error, urllib.request, uuid

HOST = os.environ["RW_API_HOST"]  # the temporary project's API domain, no scheme
assert HOST.endswith(".up.railway.app") and HOST != "", "refusing: not a Railway test domain"
API = f"https://{HOST}/api/v1"
LAB = os.path.join(os.path.dirname(os.path.abspath(__file__)), ".lab")
SECRET = os.environ["WHATSAPP_APP_SECRET"].encode()
PLAN = json.load(open(f"{LAB}/scale/plan.json")); TOK = json.load(open(f"{LAB}/scale/tokens.json"))
LEVELS = [int(x) for x in sys.argv[1].split(",")]; SECS = int(sys.argv[2]) if len(sys.argv) > 2 else 60
PHRASES = ["hi", "menu", "what do you have?", "price list please", "add 1 {item}", "how much is {item}?", "do you deliver?", "what are your opening hours?"]
RWSH = os.environ["RW_SH"]


def sql(q):
    r = subprocess.run([RWSH, "-N", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


def post_webhook(phone_id, frm, text):
    m = {"from": frm, "id": "wamid.RL" + uuid.uuid4().hex, "timestamp": str(int(time.time())), "type": "text", "text": {"body": text}}
    raw = json.dumps({"object": "whatsapp_business_account", "entry": [{"id": "WABA", "changes": [{"field": "messages", "value": {
        "messaging_product": "whatsapp", "metadata": {"display_phone_number": "961", "phone_number_id": phone_id},
        "contacts": [{"profile": {"name": "Customer"}, "wa_id": frm}], "messages": [m]}}]}]}).encode()
    sig = "sha256=" + hmac.new(SECRET, raw, hashlib.sha256).hexdigest()
    req = urllib.request.Request(API + "/webhooks/whatsapp", data=raw, method="POST", headers={"Content-Type": "application/json", "X-Hub-Signature-256": sig})
    return _do(req)


def get(path, tok):
    return _do(urllib.request.Request(API + path, headers={"Authorization": f"Bearer {tok}", "Accept": "application/json"}))


def _do(req):
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            r.read(); return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:
        return 599


def timed(fn):
    t0 = time.perf_counter(); code = fn()
    return code, (time.perf_counter() - t0) * 1000


def pct(v, p):
    return v[min(len(v) - 1, int(len(v) * p))]


ITEMS = {p["id"]: [] for p in PLAN}
for mid, name in sql("select merchant_id, name from (select merchant_id, name, row_number() over (partition by merchant_id order by sort_order) rn from products) t where rn <= 6"):
    if mid in ITEMS: ITEMS[mid].append(name)
skew = float(sql("select unix_timestamp()")[0][0]) - time.time()  # database clock minus this laptop's clock


def vu(idx, stop, res, sent):
    rnd = random.Random(idx); base = f"9617{idx:04d}"; shop = PLAN[idx % len(PLAN)]
    while time.time() < stop:
        if rnd.random() < 0.7:
            s = rnd.choice(PLAN); ph = base + f"{rnd.randint(0, 999):03d}"
            txt = rnd.choice(PHRASES).format(item=rnd.choice(ITEMS[s["id"]]))
            t = time.time(); c, ms = timed(lambda: post_webhook(s["pid"], ph, txt)); res.append(("customer message", c, ms))
            if c < 400: sent.setdefault("+" + ph, t)
        else:
            kind = rnd.choice([("menu page", "/products?page=1"), ("customers", "/customers"), ("reports", "/analytics/dashboard")])
            c, ms = timed(lambda: get(kind[1], TOK[shop["id"]])); res.append((kind[0], c, ms))
        time.sleep(rnd.uniform(0.6, 1.4))


def ready():
    return int(sql("select count(*) from jobs where available_at <= unix_timestamp()")[0][0])


for L in LEVELS:
    print(f"\n=== {L} virtual users for {SECS}s ===", flush=True)
    fj0 = int(sql("select count(*) from failed_jobs")[0][0]); res = []; sent = {}
    t_start = time.time(); stop = t_start + SECS
    with cf.ThreadPoolExecutor(L) as ex:
        [f.result() for f in [ex.submit(vu, i, stop, res, sent) for i in range(L)]]
    el = time.time() - t_start; backlog = ready(); td = time.time()
    while time.time() - td < 300 and ready() > 0: time.sleep(1)
    drain = time.time() - td; time.sleep(3)
    rows = sql(f"select to_number, min(unix_timestamp(created_at)) from whatsapp_messages where direction='outbound' and created_at >= from_unixtime({int(t_start + skew) - 5}) group by to_number")
    first = {r[0]: float(r[1]) for r in rows}
    lat = sorted(first[p] - skew - t for p, t in sent.items() if p in first)
    missing = len([p for p in sent if p not in first])
    fj1 = int(sql("select count(*) from failed_jobs")[0][0])
    codes = [c for _, c, _ in res]; bad = [c for c in codes if c >= 400 and c != 429]; thr = [c for c in codes if c == 429]
    print(f"  {len(res)} requests, {len(res) / el:.0f}/s; errors {len(bad)} ({100 * len(bad) / max(1, len(res)):.2f}%) {sorted(set(bad))[:4]}, throttled(429) {len(thr)}")
    for kind in ("customer message", "menu page", "customers", "reports"):
        v = sorted(ms for k, c, ms in res if k == kind and c < 400)
        if v: print(f"  {kind:17} n={len(v):5}  p50 {pct(v, .5):6.0f}ms  p95 {pct(v, .95):6.0f}ms  p99 {pct(v, .99):6.0f}ms  max {v[-1]:6.0f}ms")
    if lat: print(f"  time to first reply ({len(lat)} customers): p50 {pct(lat, .5):.1f}s  p95 {pct(lat, .95):.1f}s  max {lat[-1]:.1f}s; customers with no reply: {missing}")
    print(f"  queue backlog when load stopped: {backlog}; drained {drain:.1f}s later; failed jobs +{fj1 - fj0}", flush=True)
