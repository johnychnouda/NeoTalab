"""Workflow 1 (owner side): platform owner signs in, creates six test shops of different kinds,
connects each shop's (fake) WhatsApp number. Saves logins to state.json (lab only)."""
import secrets
from lib import *

suite("W1 owner creates shops")
s = state()
code, b = login(ENV["OWNER_EMAIL"], ENV["OWNER_PASSWORD"])
check("owner can sign in", code == 200 and b.get("token"), code)
s["owner"] = b["token"]
check("owner login returns no shop", b.get("merchant") is None, b.get("merchant"))
check("owner role is platform-super-admin", "platform-super-admin" in json.dumps(b.get("user")), b.get("user"))

code, b = login(ENV["OWNER_EMAIL"], "wrong-password")
check("wrong password refused", code == 422, code)

SHOPS = [
    ("rest", "QA Manakish Corner", "Restaurant / Café", "+96170111001"),
    ("pharm", "QA Pharmacy Cedar", "Pharmacy", "+96170111002"),
    ("grocer", "QA Supermarket Hamra", "Supermarket / Grocery", "+96170111003"),
    ("retail", "QA Phone Shop", "Electronics / Phones", "+96170111004"),
    ("whole", "QA Water Wholesale", "Water / Gas delivery", "+96170111005"),
    ("salon", "QA Salon Rose", "Salon / Barber / Beauty", "+96170111006"),
]
s.setdefault("shops", {})
for key, name, btype, wa in SHOPS:
    if key in s["shops"]:
        continue
    pw = secrets.token_hex(8)
    code, b = api("POST", "/admin/merchants", s["owner"], {
        "shopName": name, "whatsappNumber": wa, "password": pw, "businessType": btype,
        "subscriptionStatus": "trial", "city": "Beirut", "country": "LB"})
    ok = check(f"create shop {key} ({btype})", code == 201, (code, str(b)[:300]))
    if not ok:
        continue
    m = b["data"]
    s["shops"][key] = {"id": m["id"], "name": name, "wa": wa, "pw": pw, "phone_id": f"PNID{wa[-6:]}", "raw": {k: m.get(k) for k in ("loginEmail", "email", "slug", "status")}}
    save_state(s)
print(json.dumps({k: v["raw"] for k, v in s["shops"].items()}, indent=1))

# duplicate number refused
code, b = api("POST", "/admin/merchants", s["owner"], {"shopName": "Dup", "whatsappNumber": "+96170111001", "password": "abcdef12"})
check("duplicate WhatsApp number refused", code == 422, code)
code, b = api("POST", "/admin/merchants", s["owner"], {"shopName": "", "whatsappNumber": "", "password": ""})
check("empty shop form refused", code == 422, code)

# connect each shop's number to the fake Meta
for key, sh in s["shops"].items():
    code, b = api("PATCH", f"/admin/merchants/{sh['id']}/bot", s["owner"], {"phoneNumberId": sh["phone_id"], "accessToken": "lab-shop-token"})
    check(f"connect bot {key}", code == 200, (code, str(b)[:200]))
    code, b = api("POST", f"/admin/merchants/{sh['id']}/bot/restart", s["owner"])
    check(f"restart bot {key}", code == 200, (code, str(b)[:200]))
save_state(s)
