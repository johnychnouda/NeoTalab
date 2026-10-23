"""WhatsApp flood limit on the lab, blocked numbers, and problem alerts (recorded, listed, resolved)."""
import secrets
from lib import *

s = state(); R = s["shops"]["rest"]; PID = R["phone_id"]; OWN = s["owner"]; RO = R["token"]

suite("WhatsApp flood limit")
c = "9617730" + str(int(time.time()))[-5:]
t0 = time.time()
for i in range(45):
    wa_msg(PID, c, text=f"hi {i}")
replies = settle(t0, quiet=4.0, cap=180)
mine = [x for x in replies if x["body"].get("to", "").lstrip("+") == c]
print(f"  45 messages in one burst -> {len(mine)} replies")
check("a burst of 45 messages gets at most 40 replies (the rest are ignored)", 1 <= len(mine) <= 40, len(mine))
check("the limit stops the bot without a server error", tj('echo json_encode(DB::table("failed_jobs")->where("failed_at",">=",now()->subMinutes(10))->count());') == 0)

suite("Blocked number")
cid = tj('echo json_encode(App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + R["id"] + '")->where("phone","+' + c + '")->value("id"));')
code, _ = api("POST", f"/customers/{cid}/block", RO)
check("shop blocks the number", code == 200, code)
t1 = time.time(); wa_msg(PID, c, text="hi again"); r2 = [x for x in settle(t1, quiet=2.0, cap=20) if x["body"].get("to", "").lstrip("+") == c]
check("blocked number gets no reply", r2 == [], texts(r2))
api("POST", f"/customers/{cid}/unblock", RO)

suite("Problem alerts")
code, b = api("GET", "/admin/system-alerts", OWN)
before = len(b.get("alerts", b.get("data", []))) if isinstance(b, dict) else -1
tinker('Illuminate\\Support\\Facades\\Log::error("QA test alert ' + secrets.token_hex(3) + '", ["password"=>"should-not-appear"]);')
code, b = api("GET", "/admin/system-alerts", OWN)
items = (b.get("alerts") or b.get("data") or []) if isinstance(b, dict) else []
hit = [a for a in items if "QA test alert" in json.dumps(a)]
check("a server error is recorded and listed for the owner", code == 200 and hit, (code, str(b)[:200]))
check("the secret in the log context is hidden", hit and "should-not-appear" not in json.dumps(hit[0]), hit[:1])
if hit:
    code, b = api("POST", f"/admin/system-alerts/{hit[0]['id']}/resolve", OWN)
    check("the owner marks it fixed", code == 200, (code, str(b)[:150]))
code, b = api("GET", "/admin/system-alerts", RO)
check("a shop cannot see the platform's alerts", code == 403, code)
