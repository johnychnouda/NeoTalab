"""Workflow 1 (shop side): each shop owner signs in, sets up a catalogue with overlapping product
names, zones, hours and team. Saves ids to state.json."""
from lib import *

suite("W1 shops set up")
s = state()
for key, sh in s["shops"].items():
    if "token" not in sh:
        code, b = login(sh["raw"].get("email") or f"{sh['raw']['slug']}@merchant.neotalab", sh["pw"])
        check(f"{key} owner signs in", code == 200, code)
        sh["token"] = b["token"]
        sh["email"] = f"{sh['raw']['slug']}@merchant.neotalab"
        check(f"{key} login returns its own shop", b["merchant"]["id"] == sh["id"], b["merchant"].get("id"))
save_state(s)

def mk(key, path, body, label):
    code, b = api("POST", path, s["shops"][key]["token"], body)
    check(f"{key} create {label}", code in (200, 201), (code, str(b)[:200]))
    d = b.get("data", b) if isinstance(b, dict) else {}
    if isinstance(d, dict) and "id" not in d:
        for k in ("product", "category", "zone", "driver", "variant", "group", "modifier", "modifier_group"):
            if isinstance(d.get(k), dict):
                d = d[k]
                break
    return d.get("id") if isinstance(d, dict) else None

for key in s["shops"]:
    sh = s["shops"][key]
    if sh.get("cat"):
        continue
    sh["cat"] = mk(key, "/categories", {"name": "Main", "name_ar": "رئيسي"}, "category")
    # Same names in every shop (overlap for isolation checks), different prices.
    base = {"rest": 2.5, "pharm": 4.0, "grocer": 1.25, "retail": 99.99, "whole": 3.0, "salon": 15.0}[key]
    body = {"name": "Falafel", "name_ar": "فلافل", "price": base, "category_id": sh["cat"]}
    if key == "salon":
        body.update(name="Haircut", duration_mins=30)
    sh["p1"] = mk(key, "/products", body, "product 1")
    body2 = {"name": "Zaatar Manousheh" if key == "rest" else "Shared Item", "price": round(base * 2 + 0.33, 2), "category_id": sh["cat"]}
    if key == "salon":
        body2.update(name="Beard trim", duration_mins=15, price=7.5)
    sh["p2"] = mk(key, "/products", body2, "product 2")
    save_state(s)

# Restaurant: sizes and extras; a stocked item.
r = s["shops"]["rest"]
if not r.get("variant"):
    r["variant"] = mk("rest", f"/products/{r['p1']}/variants", {"name": "Large", "price": 4.0}, "variant")
    r["group"] = mk("rest", f"/products/{r['p1']}/modifier-groups", {"name": "Extras", "min_select": 0, "max_select": 3}, "modifier group")
    r["mod"] = mk("rest", f"/modifier-groups/{r['group']}/modifiers", {"name": "Cheese", "price_delta": 0.75}, "modifier")
    r["stock"] = mk("rest", "/products", {"name": "Last Kunafa", "price": 3.5, "category_id": r["cat"], "track_inventory": True, "stock_quantity": 1}, "stocked product")
    r["zone"] = mk("rest", "/settings/zones", {"name": "Hamra", "deliveryFee": 1.5, "minimumOrder": 5, "deliveryMins": 30}, "zone")
    r["driver"] = mk("rest", "/drivers", {"name": "QA Driver Karim", "phone": "+96171222001", "role": "driver"}, "driver")
    r["staff"] = mk("rest", "/drivers", {"name": "QA Staff Rami", "phone": "+96171222002", "role": "staff"}, "staff member")
    save_state(s)
g = s["shops"]["grocer"]
if not g.get("zone"):
    g["zone"] = mk("grocer", "/settings/zones", {"name": "Hamra", "deliveryFee": 2, "minimumOrder": 10}, "zone")
    g["driver"] = mk("grocer", "/drivers", {"name": "QA Driver Grocer", "phone": "+96171222101", "role": "driver"}, "driver")
    save_state(s)

# Lab-only dashboard accounts for the roles the app defines but cannot create (merchant-admin, merchant-staff).
if "rest_admin" not in s:
    import secrets
    for role, k in (("merchant-admin", "rest_admin"), ("merchant-staff", "rest_staff")):
        pw = secrets.token_hex(8)
        email = f"{k}@qa.test"
        out = tinker(f'$u=App\\Models\\User::create(["merchant_id"=>"{r["id"]}","name"=>"{k}","email"=>"{email}","password"=>"{pw}","status"=>"active","password_changed_at"=>now()]); $u->assignRole("{role}"); echo "ok";')
        check(f"lab account {role} created", out.endswith("ok"), out)
        code, b = login(email, pw)
        check(f"{role} signs in", code == 200, code)
        s[k] = {"email": email, "pw": pw, "token": b.get("token")}
    save_state(s)
print("saved")
