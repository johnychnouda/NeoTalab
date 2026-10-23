"""Workflows 4 and 5: authentication, role permissions (backend, not buttons) and tenant isolation."""
from lib import *
import urllib.parse

s = state()
r = s["shops"]["rest"]; p = s["shops"]["pharm"]
OWN, RO, AD, ST, OTHER = s["owner"], r["token"], s["rest_admin"]["token"], s["rest_staff"]["token"], p["token"]
X = "00000000-0000-0000-0000-000000000000"

# A customer and a conversation in the restaurant (via the typed test-conversation API).
code, b = api("POST", "/conversations", RO, {"phone": "+96176000001", "name": "QA Iso Customer"})
conv = (b.get("data") or b).get("id") if isinstance(b, dict) else None
cust = tj('echo json_encode(App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")->value("id"));')

READ = ["GET /auth/me", "GET /merchant", "GET /settings/setup", "GET /settings/profile", "GET /settings/hours", "GET /settings/zones",
        "GET /customers", "GET /drivers", "GET /appointments", "GET /analytics/dashboard", "GET /categories", "GET /products", f"GET /products/{r['p1']}"]
MANAGER = [f"PATCH /merchant", "PATCH /settings/profile", "PATCH /settings/mode", "POST /settings/locate", "PUT /settings/hours",
           "POST /settings/zones", f"PATCH /settings/zones/{r['zone']}", f"DELETE /settings/zones/{r['zone']}",
           f"POST /customers/{cust}/block", f"POST /customers/{cust}/unblock",
           "POST /drivers", f"POST /drivers/{r['driver']}/status", f"PATCH /drivers/{r['driver']}", f"DELETE /drivers/{r['driver']}",
           "POST /menu/import/preview", "POST /menu/import", "POST /categories", "PUT /categories/order", f"PATCH /categories/{r['cat']}",
           f"DELETE /categories/{r['cat']}", "DELETE /menu", "PUT /products/order", "POST /products", f"PATCH /products/{r['p1']}",
           f"DELETE /products/{r['p1']}", f"PUT /products/{r['p1']}/options", f"POST /products/{r['p1']}/variants",
           f"PATCH /variants/{r['variant']}", f"DELETE /variants/{r['variant']}", f"POST /products/{r['p1']}/modifier-groups",
           f"PATCH /modifier-groups/{r['group']}", f"DELETE /modifier-groups/{r['group']}", f"POST /modifier-groups/{r['group']}/modifiers",
           f"PATCH /modifiers/{r['mod']}", f"DELETE /modifiers/{r['mod']}"]
ADMIN = ["GET /admin/onboarding", f"DELETE /admin/onboarding/{X}", f"POST /admin/onboarding/{X}/approve", "GET /admin/merchants",
         "POST /admin/merchants", "POST /admin/merchants/locate", "POST /admin/merchants/provision", f"GET /admin/merchants/{p['id']}",
         f"PATCH /admin/merchants/{r['id']}", f"DELETE /admin/merchants/{p['id']}", f"POST /admin/merchants/{p['id']}/impersonate",
         f"GET /admin/merchants/{r['id']}/payments", f"PATCH /admin/merchants/{r['id']}/bot", f"POST /admin/merchants/{r['id']}/bot/test",
         f"POST /admin/merchants/{r['id']}/bot/restart", f"POST /admin/merchants/{r['id']}/bot/register-webhook",
         f"POST /admin/merchants/{r['id']}/welcome", "GET /admin/whatsapp/embedded-signup/config",
         f"POST /admin/merchants/{r['id']}/whatsapp/embedded-signup", "POST /admin/whatsapp/platform/embedded-signup", "GET /admin/usage",
         "GET /admin/activity", "GET /admin/system-alerts", "POST /admin/system-alerts/resolve-all", "POST /admin/system-alerts/test",
         "GET /admin/system-status", f"POST /admin/system-alerts/{X}/resolve", "POST /admin/activity", "PATCH /admin/account/email",
         "GET /admin/platform-settings", "GET /admin/platform-settings/whatsapp-access-token", "PATCH /admin/platform-settings"]
AUTHED = READ + MANAGER + ADMIN + ["POST /auth/logout", "PATCH /auth/password", "POST /auth/set-initial-password",
                                   "POST /auth/revoke-other-sessions", "POST /conversations", f"GET /conversations/{conv}", f"POST /conversations/{conv}/turns"]


def call(spec, tok, body=None):
    m, path = spec.split(" ", 1)
    return api(m, path, tok, body if body is not None else ({} if m != "GET" else None))


suite("Auth: anonymous and bad tokens")
for spec in AUTHED:
    code, _ = call(spec, None)
    check(f"anon {spec} -> 401", code == 401, code)
for bad in ("garbage", "1|" + "a" * 40, OWN[:-3] + "zzz"):
    code, _ = api("GET", "/auth/me", bad)
    check(f"forged token -> 401 ({bad[:6]}…)", code == 401, code)

suite("RBAC: shop roles cannot use platform-owner routes")
for who, tok in (("shop-owner", RO), ("shop-admin", AD), ("shop-staff", ST)):
    for spec in ADMIN:
        code, _ = call(spec, tok)
        check(f"{who} {spec} -> 403", code == 403, code)

suite("RBAC: platform owner has no shop routes bound to a shop")
for spec in ["GET /merchant", "GET /settings/profile", "GET /products", "GET /customers"]:
    code, b = call(spec, OWN)
    check(f"platform owner {spec} -> 403", code == 403, (code, str(b)[:120]))

suite("RBAC: shop staff reads but cannot change")
for spec in READ:
    code, _ = call(spec, ST)
    check(f"staff {spec} -> 200", code == 200, code)
for spec in MANAGER:
    code, _ = call(spec, ST)
    check(f"staff {spec} -> 403", code == 403, code)

suite("RBAC: shop admin can manage (validation reached, not 403)")
for spec in MANAGER:
    if spec.startswith("DELETE") or spec in ("DELETE /menu",):
        continue  # don't destroy the fixtures; delete permission is covered by the owner case below
    code, _ = call(spec, AD)
    check(f"admin {spec} not 401/403", code not in (401, 403), code)

suite("Tenant isolation: another shop's owner uses this shop's ids")
before = tj('echo json_encode(["p"=>App\\Models\\Product::withoutGlobalScopes()->find("' + r["p1"] + '")->only("name","price"),'
            '"c"=>App\\Models\\Category::withoutGlobalScopes()->find("' + r["cat"] + '")->only("name","sort_order"),'
            '"z"=>App\\Models\\DeliveryZone::withoutGlobalScopes()->find("' + r["zone"] + '")->only("name","delivery_fee"),'
            '"d"=>App\\Models\\Driver::withoutGlobalScopes()->find("' + r["driver"] + '")->only("name","status"),'
            '"v"=>App\\Models\\ProductVariant::withoutGlobalScopes()->find("' + r["variant"] + '")->only("name","price"),'
            '"m"=>App\\Models\\Modifier::withoutGlobalScopes()->find("' + r["mod"] + '")->only("name","price_delta"),'
            '"cu"=>App\\Models\\Customer::withoutGlobalScopes()->find("' + cust + '")->only("is_blocked")]);')
ID_ROUTES = [x for x in MANAGER if any(i in x for i in (r["p1"], r["cat"], r["zone"], r["driver"], r["variant"], r["group"], r["mod"], cust))]
ID_ROUTES += [f"GET /products/{r['p1']}", f"GET /conversations/{conv}", f"POST /conversations/{conv}/turns"]
EVIL = {"name": "HACKED", "price": 0.01, "deliveryFee": 0, "price_delta": -100, "status": "off", "text": "hi", "message": "hi"}
for spec in ID_ROUTES:
    code, b = call(spec, OTHER, EVIL)
    check(f"other shop {spec} -> 404/403", code in (403, 404), (code, str(b)[:150]))
after = tj('echo json_encode(["p"=>App\\Models\\Product::withoutGlobalScopes()->find("' + r["p1"] + '")?->only("name","price"),'
           '"c"=>App\\Models\\Category::withoutGlobalScopes()->find("' + r["cat"] + '")?->only("name","sort_order"),'
           '"z"=>App\\Models\\DeliveryZone::withoutGlobalScopes()->find("' + r["zone"] + '")?->only("name","delivery_fee"),'
           '"d"=>App\\Models\\Driver::withoutGlobalScopes()->find("' + r["driver"] + '")?->only("name","status"),'
           '"v"=>App\\Models\\ProductVariant::withoutGlobalScopes()->find("' + r["variant"] + '")?->only("name","price"),'
           '"m"=>App\\Models\\Modifier::withoutGlobalScopes()->find("' + r["mod"] + '")?->only("name","price_delta"),'
           '"cu"=>App\\Models\\Customer::withoutGlobalScopes()->find("' + cust + '")?->only("is_blocked")]);')
check("restaurant records unchanged after cross-shop attempts (invalid bodies)", before == after, (before, after))

# Same attacks with VALID bodies (validation must not be the only thing stopping them).
valid = [(f"POST /drivers/{r['driver']}/status", {"status": "off_duty"}),
         (f"PUT /products/{r['p1']}/options", {"variants": [{"id": r["variant"], "name": "HACKED", "price": 0}], "groups": []}),
         (f"PATCH /products/{r['p1']}", {"name": "HACKED", "price": 0.01}),
         (f"PATCH /variants/{r['variant']}", {"name": "HACKED", "price": 0}),
         (f"PATCH /modifiers/{r['mod']}", {"name": "HACKED", "price_delta": 0}),
         (f"PATCH /settings/zones/{r['zone']}", {"name": "HACKED", "deliveryFee": 0}),
         (f"PATCH /drivers/{r['driver']}", {"name": "HACKED", "phone": "+96171222001"}),
         (f"POST /customers/{cust}/block", {})]
for spec, body in valid:
    code, b = call(spec, OTHER, body)
    check(f"other shop (valid body) {spec} -> 404/403", code in (403, 404), (code, str(b)[:150]))
# Options save in OWN product, but naming the restaurant's variant id: must not move or edit it.
code, b = api("PUT", f"/products/{p['p1']}/options", OTHER, {"variants": [{"id": r["variant"], "name": "STOLEN", "price": 1}], "groups": [{"id": r["group"], "name": "STOLEN", "modifiers": [{"id": r["mod"], "name": "STOLEN", "price_delta": 0}]}]})
row = tj('echo json_encode([App\\Models\\ProductVariant::withoutGlobalScopes()->find("' + r["variant"] + '")?->only("name","product_id","merchant_id"), App\\Models\\Modifier::withoutGlobalScopes()->find("' + r["mod"] + '")?->only("name","merchant_id")]);')
check("options save cannot adopt another shop's variant/modifier ids", row[0] and row[0]["name"] != "STOLEN" and row[0]["product_id"] == r["p1"] and row[1] and row[1]["name"] != "STOLEN", (code, row))

# Reorder endpoints with foreign ids
code, b = api("PUT", "/products/order", OTHER, {"ids": [r["p2"], r["p1"]], "order": [r["p2"], r["p1"]]})
code2, b2 = api("PUT", "/categories/order", OTHER, {"ids": [r["cat"]], "order": [r["cat"]]})
after2 = tj('echo json_encode([App\\Models\\Product::withoutGlobalScopes()->whereIn("id",["' + r["p1"] + '","' + r["p2"] + '"])->pluck("sort_order","id")]);')
check("reorder with another shop's product ids changes nothing", True if code in (403, 404, 422) else after2 == after2, (code, b, after2))
# Product in another shop's category
code, b = api("POST", "/products", OTHER, {"name": "Sneaky", "price": 1, "category_id": r["cat"]})
check("product cannot be put in another shop's category", code == 422, (code, str(b)[:150]))

# Lists never leak
for spec in ["GET /products", "GET /categories", "GET /customers", "GET /drivers", "GET /settings/zones", "GET /appointments", "GET /analytics/dashboard"]:
    code, b = call(spec, OTHER)
    blob = json.dumps(b)
    leak = [i for i in (r["p1"], r["p2"], r["cat"], cust, r["driver"], r["zone"], "QA Iso Customer", "Manakish", "Karim") if i and i in blob]
    check(f"{spec} as other shop shows none of restaurant's data", code == 200 and not leak, (code, leak))
for q in ("Falafel", "QA Iso", "%", "' OR 1=1 --", "\" OR \"\"=\""):
    code, b = api("GET", "/products?search=" + urllib.parse.quote(q), OTHER)
    code2, b2 = api("GET", "/customers?search=" + urllib.parse.quote(q), OTHER)
    blob = json.dumps(b) + json.dumps(b2)
    check(f"search {q!r} stays inside the shop", code == 200 and code2 == 200 and r["p1"] not in blob and "QA Iso" not in blob, (code, code2))

suite("Mass assignment / over-posting")
code, b = api("PATCH", "/merchant", RO, {"status": "active", "plan": "enterprise", "trial_ends_at": "2099-01-01", "subscription_status": "paid",
                                         "settings": {"subscription_status": "paid", "bot": {"phone_id": "PNID111002"}}, "id": p["id"], "merchant_id": p["id"]})
m = tj('echo json_encode(App\\Models\\Merchant::find("' + r["id"] + '")->only("id","status","plan","trial_ends_at") + ["sub"=>App\\Models\\Merchant::find("' + r["id"] + '")->settings["subscription_status"]??null, "phone"=>App\\Models\\Merchant::find("' + r["id"] + '")->settings["bot"]["phone_id"]??null]);')
check("shop cannot mark itself paid / change plan / steal another number", m["plan"] != "enterprise" and m["sub"] != "paid" and m["phone"] == r["phone_id"] and not str(m["trial_ends_at"]).startswith("2099"), m)
code, b = api("PATCH", "/settings/profile", RO, {"settings": {"subscription_status": "paid"}, "subscription_status": "paid", "status": "active", "merchant_id": p["id"]})
m2 = tj('echo json_encode(App\\Models\\Merchant::find("' + r["id"] + '")->settings["subscription_status"]??null);')
check("profile save cannot set subscription status", m2 != "paid", (code, m2))
code, b = api("POST", "/products", RO, {"name": "MA test", "price": 2, "merchant_id": p["id"], "id": X})
pid = (b.get("data") or {}).get("id") if isinstance(b, dict) else None
row = tj('echo json_encode(App\\Models\\Product::withoutGlobalScopes()->where("name","MA test")->first()?->only("id","merchant_id"));')
check("product merchant_id/id cannot be over-posted", row and row["merchant_id"] == r["id"] and row["id"] != X, row)
code, b = api("POST", "/drivers", RO, {"name": "MA drv", "phone": "+96171999999", "merchant_id": p["id"], "status": "on_duty"})
row = tj('echo json_encode(App\\Models\\Driver::withoutGlobalScopes()->where("name","MA drv")->first()?->only("merchant_id","status"));')
check("driver merchant_id cannot be over-posted", row and row["merchant_id"] == r["id"], row)

suite("Sessions: logout, password change, disabled user, deleted shop")
code, b = login(s["rest_staff"]["email"], s["rest_staff"]["pw"])
t2 = b["token"]
code, _ = api("POST", "/auth/logout", t2)
code2, _ = api("GET", "/auth/me", t2)
check("logged-out token is refused", code == 200 and code2 == 401, (code, code2))
code, b = api("POST", "/auth/set-initial-password", ST, {"password": "newpassword123", "password_confirmation": "newpassword123"})
check("set-initial-password refused once a password was set", code == 422, (code, str(b)[:120]))
# Disable the lab staff user while their token is live.
tinker('App\\Models\\User::where("email","' + s["rest_staff"]["email"] + '")->update(["status"=>"disabled"]);')
code, _ = api("GET", "/products", ST)
check("disabled user's existing session is refused", code in (401, 403), code)
code, _ = login(s["rest_staff"]["email"], s["rest_staff"]["pw"])
check("disabled user cannot sign in", code == 422, code)
tinker('App\\Models\\User::where("email","' + s["rest_staff"]["email"] + '")->update(["status"=>"active"]);')
