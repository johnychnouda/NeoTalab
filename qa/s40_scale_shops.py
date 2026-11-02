"""Scale lab, step 1: creates 120 shops (20 kinds, English/Arabic/French) through the owner's API like real sign-ups,
connects each to the mock Meta, and writes the plan the bulk seeder (scale_seed.php) fills. Scale lab only."""
import random
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"], "refusing: not the scale lab"
random.seed(40)
s = state()
code, b = login(ENV["OWNER_EMAIL"], ENV["OWNER_PASSWORD"])
OWN = b["token"]; s["owner"] = OWN

TYPES = ["Restaurant / Café", "Pharmacy", "Supermarket / Grocery", "Electronics / Phones", "Water / Gas delivery",
         "Salon / Barber / Beauty", "Pet Shop", "Other", "Bakery", "Flowers", "Clothing", "Laundry", "Books",
         "Hardware", "Cosmetics", "Jewelry", "Furniture", "Toys", "Optics", "Car parts"]
SIZES = [15] * 40 + [50] * 40 + [120] * 25 + [250] * 10 + [500] * 5
random.shuffle(SIZES)
LOCALES = ["en", "ar", "fr"] * 40
random.shuffle(LOCALES)

plan = []
s.setdefault("scale", {})
for i in range(120):
    key = f"sc{i:03d}"
    if key in s["scale"]:
        plan.append(s["scale"][key]); continue
    btype = TYPES[i % len(TYPES)]
    wa = f"+9617{i:03d}{random.randint(1000, 9999)}"[:12]
    pw = "123456"
    code, b = api("POST", "/admin/merchants", OWN, {"shopName": f"Scale {btype.split(' ')[0]} {i:03d}", "whatsappNumber": wa, "password": pw,
        "businessType": btype, "subscriptionStatus": "trial", "city": "Beirut", "country": "LB", "locale": LOCALES[i]})
    if code != 201:
        print("create failed", i, code, str(b)[:200]); continue
    m = b["data"]; pid = f"PS{i:04d}"
    api("PATCH", f"/admin/merchants/{m['id']}/bot", OWN, {"phoneNumberId": pid, "accessToken": "lab-shop-token"})
    api("POST", f"/admin/merchants/{m['id']}/bot/restart", OWN)
    rec = {"key": key, "id": m["id"], "slug": m["slug"], "type": btype, "pid": pid, "wa": wa, "locale": LOCALES[i], "products": SIZES[i], "email": f"{m['slug']}@merchant.neotalab"}
    s["scale"][key] = rec; plan.append(rec); save_state(s)
json.dump(plan, open(f"{LAB}/scale/plan.json", "w"))
print("shops:", len(plan), "products planned:", sum(p["products"] for p in plan))
