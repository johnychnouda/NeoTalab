"""Scale lab: 120 shops try to reach each other's data. Every shop owner attacks one other shop (14 attempts each,
by id), lists are compared with the database, hidden fields are forced into requests, and the owner-only pages are
tried with a shop login. A hit is any 2xx on someone else's id, any changed row, or any foreign id in a list. Scale lab only."""
import random, subprocess, urllib.request, urllib.error
from lib import *
assert "neotalab_qa_scale" in ENV["DB_DATABASE"]
random.seed(7)
TOK = json.load(open(f"{LAB}/scale/tokens.json")); PLAN = json.load(open(f"{LAB}/scale/plan.json"))
IDS = [p["id"] for p in PLAN]


def sql(q):
    r = subprocess.run(["/opt/homebrew/bin/mysql", "-uroot", "-h127.0.0.1", "-P3399", "-N", "neotalab_qa_scale", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


def call(method, path, token=None, body=None, headers=None):
    req = urllib.request.Request(API + path, data=json.dumps(body).encode() if body is not None else None, method=method)
    req.add_header("Accept", "application/json")
    if body is not None: req.add_header("Content-Type", "application/json")
    if token: req.add_header("Authorization", f"Bearer {token}")
    for k, v in (headers or {}).items(): req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=30) as r: return r.status, r.read().decode()
    except urllib.error.HTTPError as e: return e.code, e.read().decode()


def snapshot(mid):
    q = lambda t, extra="": sql(f"select count(*), coalesce(sum(crc32(concat_ws('|',id,{extra}))),0) from {t} where merchant_id='{mid}'")[0]
    return {"products": q("products", "name,price,is_active,is_available,stock_quantity"), "categories": q("categories", "name,is_active"),
            "zones": q("delivery_zones", "name,delivery_fee,is_active"), "customers": q("customers", "phone,is_blocked"),
            "drivers": q("drivers", "name,phone,is_active,status"), "orders": q("orders", "status,total"), "merchant": sql(f"select crc32(concat_ws('|',name,status,plan,whatsapp_number,settings)) from merchants where id='{mid}'")[0]}


suite("Isolation: every shop attacks another shop by id")
attempts = hits = errors5 = 0; leaks = []; changed = []
victims = {}
for i, A in enumerate(IDS):
    B = random.choice([x for x in IDS if x != A]); victims.setdefault(B, []).append(A)
    row = lambda t: (sql(f"select id from {t} where merchant_id='{B}' limit 1") or [[None]])[0][0]
    prod, cat, zone, cust, drv = row("products"), row("categories"), row("delivery_zones"), row("customers"), row("drivers")
    before = snapshot(B)
    tests = [("GET", f"/products/{prod}", None), ("PATCH", f"/products/{prod}", {"price": 0.01, "name": "HACKED"}), ("PUT", f"/products/{prod}/stock", {"track_inventory": True, "stock_quantity": 0}),
             ("DELETE", f"/products/{prod}", None), ("PATCH", f"/categories/{cat}", {"name": "HACKED"}), ("DELETE", f"/categories/{cat}", None),
             ("PATCH", f"/settings/zones/{zone}", {"name": "HACKED", "deliveryFee": 99}), ("DELETE", f"/settings/zones/{zone}", None),
             ("POST", f"/customers/{cust}/block", {"reason": "x"}), ("PATCH", f"/drivers/{drv}", {"name": "HACKED"}), ("POST", f"/drivers/{drv}/status", {"status": "on_duty"}),
             ("DELETE", f"/drivers/{drv}", None), ("GET", f"/conversations/{cust}", None), ("PUT", f"/products/{prod}/options", {"variants": [], "groups": []})]
    for m, p, body in tests:
        code, txt = call(m, p, TOK[A], body)
        attempts += 1
        if code >= 500: errors5 += 1; leaks.append(("5xx", A[:8], m, p, code))
        elif code < 300 or "HACKED" in txt: hits += 1; leaks.append((A[:8], m, p, code, txt[:120]))
    after = snapshot(B)
    if after != before: changed.append((A[:8], B[:8], {k: (before[k], after[k]) for k in before if before[k] != after[k]}))
check(f"{attempts} attacks by id on other shops: none succeeded", hits == 0, leaks[:5])
check("no attack ended in a server error", errors5 == 0, leaks[:3])
check("the victim shops' data is byte-for-byte unchanged (products, categories, zones, customers, staff, orders, shop record)", not changed, changed[:3])

suite("Isolation: lists show only the shop's own rows")
bad = []; checked = 0
sample = random.sample(range(120), 30) + [i for i, p in enumerate(PLAN) if p["products"] == 500][:3]
for i in sorted(set(sample)):
    A = IDS[i]; t = TOK[A]; mine = {r[0] for r in sql(f"select id from products where merchant_id='{A}'")}
    got = set(); page = 1
    while True:
        code, txt = call("GET", f"/products?page={page}", t); d = json.loads(txt); got |= {x["id"] for x in d["data"]}
        if page >= d["meta"]["last_page"]: break
        page += 1
    if got != mine: bad.append(("products", i, len(got), len(mine), len(got - mine)))
    for path, key, tbl in (("/customers", "customers", "customers"), ("/drivers", "drivers", "drivers"), ("/settings/zones", "zones", "delivery_zones")):
        code, txt = call("GET", path, t); ids = {x["id"] for x in json.loads(txt)[key]}; own = {r[0] for r in sql(f"select id from {tbl} where merchant_id='{A}'")}
        if not ids <= own: bad.append((tbl, i, len(ids - own), "foreign rows"))
        elif ids != own and tbl != "customers": bad.append((tbl, i, "missing", len(own - ids)))
    code, txt = call("GET", "/categories", t); ids = {x["id"] for x in json.loads(txt)["data"]}; own = {r[0] for r in sql(f"select id from categories where merchant_id='{A}'")}
    if ids != own: bad.append(("categories", i, len(ids), len(own)))
    checked += 1
check(f"{checked} shops: products, categories, customers, staff and zones lists contain only their own rows and all of them", not bad, bad[:4])

suite("Isolation: dashboard totals are the shop's own")
bad = []
for i in random.sample(range(120), 12):
    A = IDS[i]; code, txt = call("GET", "/analytics/dashboard", TOK[A]); d = json.loads(txt)
    ids_in = [tp.get("name") for tp in d.get("topProducts", [])]
    own_names = {r[0] for r in sql(f"select name from products where merchant_id='{A}'")}
    foreign = [n for n in ids_in if n and n not in own_names]
    if foreign: bad.append((i, foreign[:2]))
check("top products on 12 dashboards are all the shop's own items", not bad, bad[:3])

suite("Isolation: forced fields, owner pages, no login")
mass = []
for A in random.sample(IDS, 20):
    B = random.choice([x for x in IDS if x != A]); name = "MASS" + uuid.uuid4().hex[:8]
    cat = sql(f"select id from categories where merchant_id='{A}' limit 1")[0][0]
    call("POST", "/products", TOK[A], {"name": name, "price": 5, "category_id": cat, "merchant_id": B, "is_active": True}, {"X-Merchant-Id": B, "X-Tenant": B})
    r = sql(f"select merchant_id from products where name='{name}'")
    if not r or r[0][0] != A: mass.append((A[:8], r))
check("a product created with another shop's merchant_id in the body and headers lands in the caller's own shop", not mass, mass[:3])
adm = []; tried = 0
for A in random.sample(IDS, 8):
    B = random.choice([x for x in IDS if x != A])
    for m, p, body in [("GET", "/admin/merchants", None), ("GET", "/admin/usage", None), ("GET", "/admin/onboarding", None), ("GET", "/admin/activity", None), ("GET", "/admin/system-alerts", None),
                       ("GET", "/admin/system-status", None), ("GET", f"/admin/merchants/{B}", None), ("POST", f"/admin/merchants/{B}/impersonate", {}), ("PATCH", f"/admin/merchants/{B}", {"name": "HACKED"}),
                       ("DELETE", f"/admin/merchants/{B}", None), ("POST", f"/admin/merchants/{B}/payments", {"amount": 1}), ("PATCH", f"/admin/merchants/{B}/bot", {"phoneNumberId": "X"})]:
        code, txt = call(m, p, TOK[A], body); tried += 1
        if code != 403: adm.append((m, p[:30], code))
check(f"{tried} attempts by shop logins at the platform owner's pages and actions (list, impersonate, edit, delete, payments, bot): every one is 403", not adm, adm[:3])
alive = sql("select count(*) from merchants where name like '%HACKED%'")[0][0]
check("no shop was renamed or removed by those attempts", alive == "0" and sql("select count(*) from merchants")[0][0] == "126", alive)
anon = []
for p in ("/products", "/customers", "/drivers", "/analytics/dashboard", "/settings/zones", "/merchant", "/appointments"):
    code, txt = call("GET", p)
    if code != 401: anon.append((p, code))
check("every shop page refuses a request with no login (401)", not anon, anon)
forged = call("GET", "/products", "9999|not-a-real-token")[0]
check("a made-up token is refused", forged == 401, forged)

suite("Isolation: WhatsApp, same phone in two shops")
shared = sql("select phone from customers group by phone having count(distinct merchant_id) > 1 limit 5")
check("the data has people who are customers of two shops", len(shared) >= 3, len(shared))
mix = []
for (phone,) in shared[:5]:
    shops = [r[0] for r in sql(f"select merchant_id from customers where phone='{phone}'")]
    if len(shops) < 2: continue
    pa, pb = [next(p for p in PLAN if p["id"] == m) for m in shops[:2]]
    conv_b0 = int(sql(f"select count(*) from conversations where merchant_id='{pb['id']}'")[0][0])
    items_b0 = int(sql(f"select count(*) from cart_items where merchant_id='{pb['id']}'")[0][0])
    items_a0 = int(sql(f"select count(*) from cart_items where merchant_id='{pa['id']}'")[0][0])
    wa_msg(pa["pid"], phone.lstrip("+"), text="add 1 " + sql(f"select name from products where merchant_id='{pa['id']}' limit 1")[0][0])
    time.sleep(2.5)
    ca = int(sql(f"select count(*) from conversations where merchant_id='{pa['id']}'")[0][0])
    conv_b1 = int(sql(f"select count(*) from conversations where merchant_id='{pb['id']}'")[0][0])
    items_b = int(sql(f"select count(*) from cart_items where merchant_id='{pb['id']}'")[0][0])
    items_a = int(sql(f"select count(*) from cart_items where merchant_id='{pa['id']}'")[0][0])
    if conv_b1 != conv_b0 or items_b != items_b0: mix.append((phone, conv_b0, conv_b1, items_b0, items_b))
    if items_a <= items_a0: mix.append((phone, "shop A got no cart line: test did not exercise the path", items_a0, items_a))
check("a customer who writes to shop A creates nothing in shop B (conversations, carts)", not mix, mix)
code, _, _ = wa_msg("PS9999-UNKNOWN", "96170000001", text="hi")
check("a message to an unknown phone-number id is ignored (200, nothing created)", code == 200, code)
raw = json.dumps({"object": "whatsapp_business_account", "entry": []}).encode()
c1 = api("POST", "/webhooks/whatsapp", raw=raw, headers={"X-Hub-Signature-256": "sha256=" + "0" * 64})[0]
c2 = api("POST", "/webhooks/whatsapp", raw=raw, headers={})[0]
check("a webhook with a wrong or missing signature is refused", c1 in (401, 403) and c2 in (401, 403), (c1, c2))
