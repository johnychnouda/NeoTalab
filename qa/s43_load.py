"""Scale lab load levels (no k6 needed: threads release a mixed workload for a fixed time). Each virtual user is a customer
or a shop owner on a loop: 70% customer messages to random shops, 30% owner dashboard calls (menu page, customers, reports).
Reports per level: requests/s, errors, p50/p95/p99 per call type, queue backlog at the end and the time to drain it, and peak
CPU and memory of PHP and MySQL. usage: s43_load.py LEVELS_CSV SECONDS   e.g.  10,50,100 60"""
import concurrent.futures as cf, random, statistics, subprocess, sys, threading, urllib.request, urllib.error
from lib import *
assert "neotalab_qa_scale" in ENV["DB_DATABASE"]
PLAN = json.load(open(f"{LAB}/scale/plan.json")); TOK = json.load(open(f"{LAB}/scale/tokens.json"))
LEVELS = [int(x) for x in sys.argv[1].split(",")]; SECS = int(sys.argv[2]) if len(sys.argv) > 2 else 60
PHRASES = ["hi", "menu", "what do you have?", "price list please", "add 1 {item}", "how much is {item}?", "do you deliver?", "what are your opening hours?"]


def sql(q):
    r = subprocess.run(["/opt/homebrew/bin/mysql", "-uroot", "-h127.0.0.1", "-P3399", "-N", "neotalab_qa_scale", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


ITEMS = {p["id"]: [r[0] for r in sql(f"select name from products where merchant_id='{p['id']}' order by sort_order limit 6")] for p in PLAN}


def ps_sample():
    out = subprocess.run(["ps", "-axo", "pcpu,rss,command"], capture_output=True, text=True).stdout
    php = my = 0.0; phpm = mym = 0
    for l in out.splitlines()[1:]:
        c = l.split(None, 2)
        if len(c) < 3: continue
        if "neotalab" in c[2] and "mysqld" in c[2] or ("mysqld" in c[2] and "3399" in c[2]): my += float(c[0]); mym += int(c[1])
        elif "php" in c[2] and ("artisan" in c[2] or "queue:work" in c[2]): php += float(c[0]); phpm += int(c[1])
    return php, phpm / 1024, my, mym / 1024


def timed(fn):
    t0 = time.perf_counter()
    try: code = fn()
    except Exception as e: code = 599
    return code, (time.perf_counter() - t0) * 1000


def get(path, tok):
    req = urllib.request.Request(API + path, headers={"Authorization": f"Bearer {tok}", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r: r.read(); return r.status
    except urllib.error.HTTPError as e: return e.code


def vu(idx, stop, res):
    rnd = random.Random(idx); phone_base = f"9617{idx:04d}"
    shop = PLAN[idx % len(PLAN)]; n = 0
    while time.time() < stop:
        n += 1
        if rnd.random() < 0.7:
            s = rnd.choice(PLAN); ph = phone_base + f"{rnd.randint(0, 999):03d}"
            txt = rnd.choice(PHRASES).format(item=rnd.choice(ITEMS[s["id"]]))
            c, ms = timed(lambda: wa_msg(s["pid"], ph, text=txt)[0]); res.append(("customer message", c, ms))
        else:
            kind = rnd.choice([("menu page", "/products?page=1"), ("customers", "/customers"), ("reports", "/analytics/dashboard")])
            c, ms = timed(lambda: get(kind[1], TOK[shop["id"]])); res.append((kind[0], c, ms))
        time.sleep(rnd.uniform(0.6, 1.4))  # a person pauses; also keeps one token under the 120/min limit


def pct(v, p): return v[min(len(v) - 1, int(len(v) * p))]


for L in LEVELS:
    suite(f"Load level: {L} virtual users for {SECS}s")
    jobs0 = int(sql("select count(*) from jobs where available_at <= unix_timestamp()")[0][0]); fj0 = int(sql("select count(*) from failed_jobs")[0][0])
    res = []; peak = [0, 0, 0, 0]; stop_s = threading.Event()

    def sampler():
        while not stop_s.is_set():
            s = ps_sample(); peak[:] = [max(a, b) for a, b in zip(peak, s)]; time.sleep(1.5)
    th = threading.Thread(target=sampler); th.start()
    stop = time.time() + SECS; t0 = time.time()
    with cf.ThreadPoolExecutor(L) as ex:
        futs = [ex.submit(vu, i, stop, res) for i in range(L)]
        [f.result() for f in futs]
    el = time.time() - t0
    backlog = int(sql("select count(*) from jobs where available_at <= unix_timestamp()")[0][0])
    td = time.time()
    while time.time() - td < 300 and int(sql("select count(*) from jobs where available_at <= unix_timestamp()")[0][0]) > 0: time.sleep(1)
    drain = time.time() - td; stop_s.set(); th.join()
    fj1 = int(sql("select count(*) from failed_jobs")[0][0])
    codes = [c for _, c, _ in res]; bad = [c for c in codes if c >= 400 and c != 429]; thr = [c for c in codes if c == 429]
    print(f"  {len(res)} requests, {len(res) / el:.0f}/s; errors {len(bad)} ({100 * len(bad) / max(1, len(res)):.2f}%), throttled(429) {len(thr)}")
    for kind in ("customer message", "menu page", "customers", "reports"):
        v = sorted(ms for k, c, ms in res if k == kind and c < 400)
        if v: print(f"  {kind:17} n={len(v):5}  p50 {pct(v, .5):6.0f}ms  p95 {pct(v, .95):6.0f}ms  p99 {pct(v, .99):6.0f}ms  max {v[-1]:6.0f}ms")
    print(f"  queue backlog at end: {backlog} jobs; drained in {drain:.1f}s; failed jobs +{fj1 - fj0}; peak PHP CPU {peak[0]:.0f}% mem {peak[1]:.0f}MB; peak MySQL CPU {peak[2]:.0f}% mem {peak[3]:.0f}MB")
    allv = sorted(ms for k, c, ms in res if c < 400)
    check(f"{L} users: no server errors (5xx / timeouts)", len(bad) == 0, collections_ := (len(bad), sorted(set(bad))[:5]))
    check(f"{L} users: 95% of calls under 2 s", bool(allv) and pct(allv, .95) < 2000, f"p95 {pct(allv, .95):.0f}ms" if allv else "none")
    check(f"{L} users: queue drained within 60 s of stopping and no job failed", drain < 60 and fj1 == fj0, (drain, fj1 - fj0))
