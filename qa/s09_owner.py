"""Platform owner: portal data, settings and secrets, merchant lifecycle (trial expiry, suspend,
reactivate, delete), account security."""
import subprocess, secrets
from lib import *

s = state(); OWN = s["owner"]
suite("Owner portal: lists and reports")
for path in ["/admin/merchants", "/admin/onboarding", "/admin/usage", "/admin/activity", "/admin/system-alerts", "/admin/system-status", "/admin/platform-settings", "/admin/whatsapp/embedded-signup/config"]:
    code, b = api("GET", path, OWN)
    check(f"owner GET {path} -> 200", code == 200, (code, str(b)[:150]))
code, b = api("GET", "/admin/merchants", OWN)
names = json.dumps(b)
check("merchant list shows the lab shops", all(n in names for n in ("QA Manakish", "QA Supermarket", "QA Phone Shop", "QA Salon")), names[:200])
code, b = api("GET", f"/admin/merchants/{s['shops']['rest']['id']}", OWN)
blob = json.dumps(b)
check("merchant detail never contains the shop's WhatsApp access token", "lab-shop-token" not in blob, blob[:200])
check("merchant detail has no password hash", "$2y$" not in blob and '"password"' not in blob, blob[:200])
code, b = api("GET", "/admin/platform-settings", OWN)
check("platform settings response hides the platform token", "lab-platform-token" not in json.dumps(b), str(b)[:200])
code, b = api("GET", "/admin/platform-settings/whatsapp-access-token", OWN)
check("owner can reveal the platform token on purpose", code in (200, 404), code)

suite("Owner portal: settings validation")
code, b = api("PATCH", "/admin/platform-settings", OWN, {"subscription_price": -5})
check("negative subscription price refused", code == 422, (code, str(b)[:150]))
code, b = api("PATCH", "/admin/platform-settings", OWN, {"trial_days": 100000})
print("  trial_days 100000 ->", code, str(b)[:150])
code, b = api("PATCH", f"/admin/merchants/{s['shops']['rest']['id']}", OWN, {"status": "deleted-forever"})
check("unknown merchant status refused", code == 422, code)
code, b = api("PATCH", f"/admin/merchants/{s['shops']['rest']['id']}", OWN, {"monthlyFee": -1})
check("negative fee refused", code == 422, code)
code, b = api("PATCH", "/admin/account/email", OWN, {"email": "new-owner@qa.test", "current_password": "wrong"})
check("owner email change needs the right password", code == 422, code)

suite("Lifecycle: trial ends unpaid → suspended → reactivated")
W = s["shops"]["whole"]
# trials:expire is global: give every other lab shop a long trial first, so only the shop under test can expire.
tinker(f'App\\Models\\Merchant::withoutGlobalScopes()->where("id","!=","{W["id"]}")->update(["trial_ends_at"=>"2099-01-01","status"=>"active"]);')
tinker(f'$m=App\\Models\\Merchant::find("{W["id"]}"); $st=$m->settings ?? []; $st["subscription_status"]="trial"; $m->forceFill(["trial_ends_at"=>now()->subHour(),"status"=>"active","settings"=>$st])->save();')
out = subprocess.run([f"{LAB}/lab.sh", "php", "artisan", "trials:expire"], capture_output=True, text=True, timeout=120)
st = tj(f'echo json_encode(App\\Models\\Merchant::find("{W["id"]}")->status);')
check("trials:expire suspends the shop whose trial ended", st == "suspended", (st, out.stdout[-200:], out.stderr[-300:]))
others = tj('echo json_encode(App\\Models\\Merchant::where("status","suspended")->pluck("name"));')
check("only that shop was suspended", others == ["QA Water Wholesale"], others)
cust = "96178100" + str(int(time.time()))[-5:]
code, mid, t0 = wa_msg(W["phone_id"], cust, text="hi"); settle(t0, quiet=1.0)
check("suspended shop's customers get no reply", len(outbox(to=cust, since=t0)) == 0)
code, b = login(W["email"], W["pw"])
check("suspended shop owner can still sign in (to pay)", code == 200, code)
code, b = api("PATCH", f"/admin/merchants/{W['id']}", OWN, {"status": "active", "trialEndsAt": "2099-01-01"})
check("owner reactivates and extends the trial", code == 200, code)
code, mid, t0 = wa_msg(W["phone_id"], cust, text="hi"); settle(t0)
check("reactivated shop answers again", len(outbox(to=cust, since=t0)) >= 1)

suite("Lifecycle: owner deletes a shop")
pw = secrets.token_hex(6)
code, b = api("POST", "/admin/merchants", OWN, {"shopName": "QA Throwaway Shop", "whatsappNumber": "+96170111077", "password": pw, "businessType": "Other"})
tid = b["data"]["id"]
code, lb = login("qa-throwaway-shop@merchant.neotalab", pw)
ttok = lb.get("token") if isinstance(lb, dict) else None
check("throwaway shop owner signs in", code == 200 and ttok, code)
api("POST", "/products", ttok, {"name": "Temp", "price": 1})
code, b = api("DELETE", f"/admin/merchants/{tid}", OWN)
check("owner deletes the shop", code in (200, 204), code)
code, _ = api("GET", "/products", ttok)
check("deleted shop's open session is refused", code in (401, 403), code)
code, _ = login("qa-throwaway-shop@merchant.neotalab", pw)
check("deleted shop's owner cannot sign in", code == 422, code)
left = tj(f'echo json_encode(["products"=>App\\Models\\Product::withoutGlobalScopes()->where("merchant_id","{tid}")->count(),"tokens"=>DB::table("personal_access_tokens")->whereNotIn("tokenable_id", App\\Models\\User::pluck("id"))->count()]);')
print("  rows left behind after delete:", left)
