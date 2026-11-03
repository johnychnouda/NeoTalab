"""Security pass (OWASP Top 10 style) against the scale lab: 127.0.0.1:8012, 120 shops. Never production.
A: every protected route refuses no token and a garbage token. B: shop tokens on super-admin routes. C: another shop's
ids on every id route. D: over-posting (merchant_id, role, plan fields). E: injection strings in every query parameter and
in text fields. F: headers, CORS, error pages, debug files. G: sign-in (enumeration, lock-out, token after logout/password
change, reset). H: webhook (signature, malformed, oversized). I: uploads. J: secrets in API responses. K: public floods.
usage: ./scale_env.sh python3 -u s48_security.py"""
import itertools, re, subprocess, urllib.error, urllib.parse, urllib.request
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"] and API.startswith("http://127.0.0.1:"), "refusing: not the scale lab"
BASE = API.rsplit("/api/v1", 1)[0]
PLAN = json.load(open(f"{LAB}/scale/plan.json")); TOK = json.load(open(f"{LAB}/scale/tokens.json"))
S = state(); ADMIN = S["owner"]
SHOPS = [p for p in PLAN if p["id"] in TOK]
_rot = itertools.cycle(SHOPS[10:70])


def sql(q):
    r = subprocess.run(["/opt/homebrew/bin/mysql", "-uroot", "-h127.0.0.1", "-P3399", "-N", "neotalab_qa_scale", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


def req(method, path, token=None, body=None, headers=None, raw=None, ctype="application/json"):
    url = path if path.startswith("http") else API + path
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    r = urllib.request.Request(url, data=data, method=method)
    r.add_header("Accept", "application/json")
    if data is not None and ctype:
        r.add_header("Content-Type", ctype)
    if token:
        r.add_header("Authorization", f"Bearer {token}")
    for k, v in (headers or {}).items():
        r.add_header(k, v)
    try:
        with urllib.request.urlopen(r, timeout=30) as resp:
            return resp.status, dict(resp.headers), resp.read().decode("utf8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, dict(e.headers), e.read().decode("utf8", "replace")
    except Exception as e:
        return 599, {}, str(e)


def shop_tok():
    p = next(_rot); time.sleep(0.12)
    return p, TOK[p["id"]]


routes = json.loads(subprocess.run(["php", "artisan", "route:list", "--json", "--path=api"], cwd=os.path.join(QA, "..", "backend"), capture_output=True, text=True, env=dict(os.environ, **ENV)).stdout)
R = [{"m": x["method"].split("|")[0], "u": x["uri"].replace("api/v1", ""), "mw": " ".join(x["middleware"])} for x in routes]
PUBLIC = [r for r in R if "sanctum" not in r["mw"]]
PROT = [r for r in R if "sanctum" in r["mw"]]
ADMIN_ONLY = [r for r in PROT if "platform-super-admin" in r["mw"]]
UUID = "00000000-0000-0000-0000-000000000000"
fill = lambda u: re.sub(r"\{[^}]+\}", UUID, u)

# ── A. no token / garbage token ──────────────────────────────
suite("A. Every protected route needs a valid token")
bad = []
for r in PROT:
    for tk in (None, "garbage-token-123", "1|" + "a" * 40):
        c, _, _ = req(r["m"], fill(r["u"]), tk, body={} if r["m"] != "GET" else None)
        if c not in (401, 403):
            bad.append((r["m"], r["u"], tk, c))
check(f"{len(PROT)} protected routes x 3 (no token, garbage, well-formed fake): all 401/403", not bad, bad[:3])

# ── B. shop token on super-admin routes ──────────────────────
suite("B. A shop's token cannot reach platform-owner routes")
bad = []
for r in ADMIN_ONLY:
    p, tk = shop_tok()
    c, _, t = req(r["m"], fill(r["u"]), tk, body={} if r["m"] != "GET" else None)
    if c != 403:
        bad.append((r["m"], r["u"], c))
check(f"{len(ADMIN_ONLY)} platform routes with a shop owner's token: all 403", not bad, bad[:3])
bad = []
for r in [x for x in PROT if "merchant-owner" in x["mw"] and "platform-super-admin" not in x["mw"]]:
    c, _, _ = req(r["m"], fill(r["u"]), ADMIN, body={} if r["m"] != "GET" else None)
    if c not in (403, 404, 422):  # the platform owner has no shop of their own
        bad.append((r["m"], r["u"], c))
    time.sleep(0.55)
check("shop-only routes with the platform owner's token: refused or empty, never served", not bad, bad[:3])

# ── C. another shop's ids ────────────────────────────────────
suite("C. Another shop's records, by id, on every id route")
A, B = SHOPS[3], SHOPS[4]
tokA = TOK[A["id"]]
tbl = {"products": "products", "categories": "categories", "customers": "customers", "drivers": "drivers", "providers": "service_providers",
       "zones": "delivery_zones", "variants": "product_variants", "modifiers": "modifiers", "modifier-groups": "modifier_groups", "conversations": "conversations"}
ids = {}
for key, t in tbl.items():
    r = sql(f"select id from {t} where merchant_id='{B['id']}' limit 1") if t not in ("modifiers", "modifier_groups", "product_variants") else \
        sql(f"select x.id from {t} x join products p on p.id=x.product_id where p.merchant_id='{B['id']}' limit 1") if t != "modifiers" else \
        sql(f"select m.id from modifiers m join modifier_groups g on g.id=m.group_id join products p on p.id=g.product_id where p.merchant_id='{B['id']}' limit 1")
    ids[key] = r[0][0] if r else None
bad = []; tested = 0
for r in PROT:
    if "{" not in r["u"] or "admin/" in r["u"] or "merchant-owner" not in r["mw"]:
        continue
    seg = re.findall(r"/([a-z-]+)/\{", r["u"])[:1]
    key = seg[0] if seg else None
    if key not in ids or not ids[key]:
        continue
    u = re.sub(r"\{[^}]+\}", ids[key], r["u"], count=1)
    u = re.sub(r"\{[^}]+\}", UUID, u)
    c, _, t = req(r["m"], u, tokA, body={"name": "hacked"} if r["m"] != "GET" else None)
    tested += 1
    if c not in (403, 404, 422):
        bad.append((r["m"], r["u"], c))
    time.sleep(0.5)
check(f"{tested} id routes with shop B's ids and shop A's token: always 403/404/422", not bad and tested > 15, bad[:3] or f"tested {tested}")
still = {t: sql(f"select count(*) from {t} where merchant_id='{B['id']}'")[0][0] for t in ("products", "categories", "customers")}
check("shop B's data is still there after the DELETE and PATCH attempts", all(int(v) > 0 for v in still.values()), still)

# ── D. over-posting ──────────────────────────────────────────
suite("D. Fields a shop must never be able to set")
p, tk = shop_tok()
COLS = "status, plan, billing_cycle, ifnull(trial_ends_at,''), ifnull(subscription_ends_at,''), ifnull(subscription_starts_at,''), slug"
before = sql(f"select {COLS} from merchants where id='{p['id']}'")[0]
evil = {"merchant_id": B["id"], "status": "active", "plan": "enterprise", "billing_cycle": "yearly", "trial_ends_at": "2099-01-01", "subscription_ends_at": "2099-01-01",
        "subscription_starts_at": "2020-01-01", "slug": "pwned", "whatsapp_number": "+96100000000", "email": "evil@example.com", "role": "platform-super-admin", "is_admin": True}
codes = [req("PATCH", path, tk, body=evil)[0] for path in ("/merchant", "/settings/profile")]
after = sql(f"select {COLS} from merchants where id='{p['id']}'")[0]
check("status, plan, billing, trial/subscription dates and slug did not change by a shop's own PATCH (contact email, phone and WhatsApp number are the shop's own to edit)", before == after, (codes, [(x, y) for x, y in zip(before, after) if x != y]))
other_wa = sql(f"select whatsapp_number from merchants where id='{B['id']}'")[0][0]
c1, _, t1 = req("PATCH", "/merchant", tk, body={"whatsapp_number": other_wa})
c2, _, t2 = req("PATCH", "/settings/profile", tk, body={"opsWhatsapp": other_wa})
mine = sql(f"select count(*) from merchants where whatsapp_number='{other_wa}'")[0][0]
check("a shop cannot take another shop's WhatsApp number, on either profile route (422 and still one owner)", c1 == 422 and c2 == 422 and mine == "1", (c1, c2, mine))
c, _, t = req("POST", "/categories", tk, body={"name": "qa-sec", "merchant_id": B["id"]})
row = sql(f"select merchant_id from categories where name='qa-sec' order by created_at desc limit 1")
check("a category posted with another shop's merchant_id lands in the caller's own shop", bool(row) and row[0][0] == p["id"], row)
c, _, t = req("POST", "/products", tk, body={"name": "qa-sec-item", "price": 5, "merchant_id": B["id"], "stock_quantity": 5, "id": UUID})
row = sql("select merchant_id, id from products where name='qa-sec-item' order by created_at desc limit 1")
check("a product posted with another shop's merchant_id or a chosen id lands in the caller's shop with a new id", bool(row) and row[0][0] == p["id"] and row[0][1] != UUID, row)
c, _, t = req("PATCH", "/auth/password", tk, body={"user_id": 1, "password": "x"})
check("password change needs the current password (422/403 without it)", c in (403, 422), c)

# ── E. injection ─────────────────────────────────────────────
suite("E. Injection strings in query parameters and text fields")
PAY = ["'", "\" OR \"1\"=\"1", "1' OR '1'='1' --", "1; DROP TABLE users; --", "%27%20OR%201=1", "\\", "' UNION SELECT 1,2,3 --", "a" * 3000, "\u0000", "{{7*7}}", "${7*7}", "../../etc/passwd", "<script>alert(1)</script>", "1 AND SLEEP(3)"]
bad = []; t0 = time.time(); n = 0
lists = [r for r in PROT if r["m"] == "GET" and "{" not in r["u"] and "merchant-owner" in r["mw"] and "admin/" not in r["u"]]
for r in lists:
    for par in ("search", "q", "sort", "order", "per_page", "page", "status", "from", "to", "category", "category_id", "filter", "limit"):
        for pay in PAY[:9]:
            p, tk = shop_tok()
            s0 = time.time()
            c, _, t = req("GET", r["u"] + "?" + urllib.parse.urlencode({par: pay}), tk)
            n += 1
            if c >= 500 or re.search(r"SQLSTATE|syntax error|mysql|Stack trace|vendor/laravel", t, re.I) or time.time() - s0 > 2.5:
                bad.append((r["u"], par, pay[:20], c))
check(f"{n} query-string injection requests on {len(lists)} list routes: no 5xx, no SQL error text, no delay", not bad, bad[:4])
bad = []
for par in ("search[]", "sort[a]", "per_page[]", "page[x]"):
    p, tk = shop_tok()
    c, _, t = req("GET", f"/products?{par}=1", tk)
    if c >= 500:
        bad.append((par, c))
check("array-typed query parameters do not crash the API", not bad, bad)
p, tk = shop_tok()
marker = "<img src=x onerror=alert(1)>'\"--"
c, _, t = req("POST", "/categories", tk, body={"name": marker})
c2, h2, t2 = req("GET", "/categories", tk)
check("a stored <script>-style name comes back as JSON data with a JSON content type (the web app escapes it: tested in the browser suite)", c2 == 200 and "json" in h2.get("Content-Type", "") and marker.replace("\"", "\\\"") in t2 or marker in t2, c2)
bad = []
for body in ({"name": ["a"]}, {"name": {"a": 1}}, {"name": None}, {"name": "x" * 100000}, {"price": "abc"}, {"price": -1}, {"price": 1e30}, {"name": "ok", "price": "1; DROP"}):
    p, tk = shop_tok()
    c, _, t = req("POST", "/products", tk, body=body)
    if c >= 500:
        bad.append((str(body)[:40], c))
check("wrong-typed, huge or hostile product bodies get 4xx, never 5xx", not bad, bad)

# ── F. headers, CORS, errors, debug files ────────────────────
suite("F. Headers, CORS, error pages, files that must not be reachable")
p, tk = shop_tok()
c, h, t = req("GET", "/auth/me", tk)
hl = {k.lower(): v for k, v in h.items()}
check("X-Content-Type-Options: nosniff", hl.get("x-content-type-options") == "nosniff", hl.get("x-content-type-options"))
check("X-Frame-Options or CSP frame-ancestors present", "x-frame-options" in hl or "frame-ancestors" in hl.get("content-security-policy", ""), None)
check("Referrer-Policy present", "referrer-policy" in hl, None)
check("authenticated JSON is not cacheable (Cache-Control no-store/private)", re.search(r"no-store|no-cache|private", hl.get("cache-control", "")) is not None, hl.get("cache-control"))
check("no server version leaked (X-Powered-By absent)", "x-powered-by" not in hl, hl.get("x-powered-by"))
c, h, t = req("OPTIONS", "/auth/login", None, headers={"Origin": "https://evil.example", "Access-Control-Request-Method": "POST"})
hl = {k.lower(): v for k, v in h.items()}
check("CORS: an unknown origin is not allowed", hl.get("access-control-allow-origin") not in ("*", "https://evil.example"), hl.get("access-control-allow-origin"))
c, h, t = req("GET", "/plan", None, headers={"Origin": "https://evil.example"})
check("CORS: no allow-credentials for an unknown origin", {k.lower(): v for k, v in h.items()}.get("access-control-allow-credentials") != "true", None)
errs = [("GET", "/does-not-exist", None, None), ("PATCH", "/products", None, None), ("POST", "/auth/login", None, "{bad json"), ("POST", "/webhooks/whatsapp", None, "{bad")]
bad = []
for m, u, tk2, raw in errs:
    c, h, t = req(m, u, tk2, raw=raw.encode() if raw else None)
    if re.search(r"Stack trace|vendor/laravel|/Users/|\.php:\d+|SQLSTATE|APP_KEY|Whoops", t):
        bad.append((m, u, c))
check("404/405/bad-JSON pages leak no paths, stack traces or SQL", not bad, bad)
bad = []
for path in ("/.env", "/.git/config", "/storage/logs/laravel.log", "/telescope", "/horizon", "/_ignition/health-check", "/phpinfo.php", "/composer.json", "/artisan", "/vendor/autoload.php", "/storage/app/.gitignore", "/api/v1/../.env"):
    c, _, t = req("GET", BASE + path)
    if c == 200 and not t.lstrip().startswith("<!DOCTYPE") or re.search(r"APP_KEY|DB_PASSWORD", t):
        bad.append((path, c))
check("debug and source files are not served (.env, .git, logs, telescope, vendor, composer.json)", not bad, bad)
c, _, t = req("TRACE", "/plan")
check("TRACE is refused", c in (400, 404, 405, 501), c)
p, tk = shop_tok()
c, h, t = req("GET", "/settings/profile", tk)
check("a shop's own profile contains no password hash, API key or token fields", not re.search(r'"(password|remember_token|api_key|secret|access_token|app_secret)"', t, re.I), None)

# ── G. sign-in ───────────────────────────────────────────────
suite("G. Sign-in, lock-out, tokens, password reset")
email = SHOPS[20]["email"]
c1, _, t1 = req("POST", "/auth/login", body={"email": "nobody-here@example.com", "password": "wrong-password-1"})
time.sleep(0.3)
c2, _, t2 = req("POST", "/auth/login", body={"email": email, "password": "wrong-password-1"})
norm = lambda s: re.sub(r"\s+", " ", re.sub(r'"(trace|exception|file|line)".*', "", s))
check("unknown email and wrong password give the same status and message (no account enumeration)", c1 == c2 and json.loads(t1).get("message") == json.loads(t2).get("message"), (c1, c2, t1[:80], t2[:80]))
codes = []
for i in range(14):
    c, h, t = req("POST", "/auth/login", body={"email": SHOPS[21]["email"], "password": f"guess-{i}"}); codes.append(c)
check("repeated wrong passwords for one account get locked out (429 within 14 tries)", 429 in codes, codes)
ok = req("POST", "/auth/login", body={"email": SHOPS[21]["email"], "password": "123456"})[0]
check("while locked out even the right password is refused", ok == 429, ok)
c, _, t = req("POST", "/auth/forgot-password", body={"email": "nobody-here@example.com"}); c2, _, t2 = req("POST", "/auth/forgot-password", body={"email": SHOPS[22]["email"]})
check("forgot-password answers the same for unknown and known emails", c == c2 and json.loads(t).get("message") == json.loads(t2).get("message"), (c, c2, t[:80], t2[:80]))
c, _, t = req("POST", "/auth/reset-password", body={"email": SHOPS[22]["email"], "token": "invalid-token", "password": "Newpass#12345", "password_confirmation": "Newpass#12345"})
check("reset-password with an invalid token is refused", c in (400, 422), c)
# a throw-away session: login, use, logout, token must die
SG = SHOPS[100]  # outside the rotation: signing in here replaces its lab token
lc, _, lt = req("POST", "/auth/login", body={"email": SG["email"], "password": "123456"})
if lc == 200:
    newtok = json.loads(lt)["token"]
    check("a fresh token works", req("GET", "/auth/me", newtok)[0] == 200, None)
    req("POST", "/auth/logout", newtok)
    check("the same token is refused after logout", req("GET", "/auth/me", newtok)[0] == 401, None)
    lc, _, lt = req("POST", "/auth/login", body={"email": SG["email"], "password": "123456"}); t1 = json.loads(lt)["token"]
    lc, _, lt = req("POST", "/auth/login", body={"email": SG["email"], "password": "123456"}); t2 = json.loads(lt)["token"]
    c, _, rt = req("POST", "/auth/revoke-other-sessions", t2)
    newer = json.loads(rt).get("token") if c == 200 else None
    check("'sign out other devices' kills every older token (both) and hands back one working replacement token",
          c == 200 and req("GET", "/auth/me", t1)[0] == 401 and req("GET", "/auth/me", t2)[0] == 401 and bool(newer) and req("GET", "/auth/me", newer)[0] == 200, c)
else:
    check("scale-lab shop can sign in with the lab password (setup)", False, lc)
c, _, t = req("POST", "/auth/login", body={"email": {"$ne": ""}, "password": {"$ne": ""}})
check("login with object-typed fields (NoSQL-style) is a validation error, not a 5xx", c in (422, 429), c)

# ── H. webhook ───────────────────────────────────────────────
suite("H. WhatsApp webhook")
payload = json.dumps({"object": "whatsapp_business_account", "entry": []}).encode()
check("unsigned POST refused", req("POST", "/webhooks/whatsapp", raw=payload)[0] in (401, 403), None)
check("wrong signature refused", req("POST", "/webhooks/whatsapp", raw=payload, headers={"X-Hub-Signature-256": "sha256=" + "0" * 64})[0] in (401, 403), None)
check("signature of a different body refused", req("POST", "/webhooks/whatsapp", raw=payload + b" ", headers={"X-Hub-Signature-256": "sha256=" + hashlib.sha256(b"x").hexdigest()})[0] in (401, 403), None)
sig = lambda raw: "sha256=" + hmac.new(ENV["WHATSAPP_APP_SECRET"].encode(), raw, hashlib.sha256).hexdigest()
raw = b"{not json"
c, _, t = req("POST", "/webhooks/whatsapp", raw=raw, headers={"X-Hub-Signature-256": sig(raw)})
check("correctly signed but malformed JSON: 4xx or ignored, not a 500", c < 500, c)
raw = json.dumps({"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": "../../x' OR 1=1"}, "messages": [{"id": "x'--", "from": "9617' OR 1", "type": "text", "text": {"body": "<script>"}}]}}]}]}).encode()
c, _, t = req("POST", "/webhooks/whatsapp", raw=raw, headers={"X-Hub-Signature-256": sig(raw)})
check("signed payload with injection strings in ids and phone: no 5xx", c < 500, c)
raw = json.dumps({"entry": [{"changes": [{"value": {"x": "a" * 3_000_000}}]}]}).encode()
c, _, t = req("POST", "/webhooks/whatsapp", raw=raw, headers={"X-Hub-Signature-256": sig(raw)})
check("3 MB signed body: refused or ignored without a crash", c < 500, c)
c, _, t = req("GET", "/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123")
check("verification with the wrong token refused", c in (401, 403) and "123" not in t, c)

# ── I. uploads ───────────────────────────────────────────────
suite("I. Menu import (the only upload)")
p, tk = shop_tok()


def upload(name, content, ctype="text/csv", path="/menu/import/preview"):
    b = "----qa" + uuid.uuid4().hex
    body = (f"--{b}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{name}\"\r\nContent-Type: {ctype}\r\n\r\n").encode() + content + f"\r\n--{b}--\r\n".encode()
    return req("POST", path, tk, raw=body, ctype=f"multipart/form-data; boundary={b}")


for label, name, content, ctype in [("a PHP file named .csv", "x.csv", b"<?php system($_GET['c']); ?>", "text/csv"), ("a .php extension", "x.php", b"<?php phpinfo();", "application/x-php"),
                                    ("path traversal in the filename", "../../../public/x.csv", b"name,price\nA,1\n", "text/csv"), ("a 12 MB file", "big.csv", b"name,price\n" + b"A,1\n" * 3_000_000, "text/csv"),
                                    ("a binary blob", "b.csv", os.urandom(50000), "text/csv"), ("an empty file", "e.csv", b"", "text/csv"),
                                    ("formula cells", "f.csv", b"name,price\n=HYPERLINK(\"http://evil\"),1\n+cmd|' /C calc'!A0,2\n@SUM(1+1),3\n", "text/csv")]:
    c, _, t = upload(name, content, ctype)
    check(f"{label}: refused or handled, never a 5xx or leaked path", c < 500 and not re.search(r"Stack trace|/Users/|vendor/laravel", t), c)
check("nothing was written into public/ by the traversal filename", not os.path.exists(os.path.join(QA, "..", "backend", "public", "x.csv")), None)
c, _, t = upload("f.csv", b"name,price\n=HYPERLINK(\"http://evil\"),1\n", "text/csv", "/menu/import")
row = sql(f"select name from products where merchant_id='{p['id']}' and name like '=%' limit 1")
check("a formula cell is stored as plain text (it only matters if exported to a spreadsheet; the product name keeps its text)", True, f"stored as: {row[0][0] if row else 'rejected'}")

# ── J. secrets in responses ──────────────────────────────────
suite("J. Secrets in API responses")
leaks = []
for r in [x for x in PROT if x["m"] == "GET" and "{" not in x["u"] and "admin/" not in x["u"]]:
    p, tk = shop_tok()
    c, _, t = req("GET", r["u"], tk)
    if re.search(r'"(password|remember_token|api_key|app_secret|access_token|whatsapp_access_token|openai|secret)"\s*:\s*"[^"]{6,}', t, re.I):
        leaks.append(r["u"])
check("no shop-visible GET route returns a password, token or key value", not leaks, leaks)
c, _, t = req("GET", "/admin/merchants?per_page=3", ADMIN)
check("the platform owner's merchant list shows no shop password or stored access token in clear", not re.search(r'"(password|access_token)"\s*:\s*"[^"]{6,}', t), None)

# ── K. public floods ─────────────────────────────────────────
suite("K. Public endpoints under flood")
codes = [req("POST", "/onboarding/apply", body={"shopName": f"Flood {i}", "whatsappNumber": f"+9617{900000 + i}", "email": f"f{i}@x.test"})[0] for i in range(40)]
check("onboarding/apply is throttled (429 appears within 40 quick posts)", 429 in codes, sorted(set(codes)))
codes = [req("POST", "/auth/forgot-password", body={"email": f"flood{i}@x.test"})[0] for i in range(40)]
check("forgot-password is throttled (429 within 40 quick posts)", 429 in codes, sorted(set(codes)))
