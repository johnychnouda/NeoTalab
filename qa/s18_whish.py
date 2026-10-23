"""Whish payment journey against the Whish stand-in (SIMULATED Whish, not the real service).
Needs WHISH_BASE_URL=http://127.0.0.1:8029 in the lab env and qa/mock_whish.py running."""
import re, urllib.request, uuid
from lib import *

s = state(); G = s["shops"]["grocer"]; PID = G["phone_id"]
RUN = str(int(time.time()))[-5:]
WLOG = f"{LAB}/whish.jsonl"


def say(frm, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    return texts([x for x in settle(t0) if x["body"].get("to", "").lstrip("+") == frm])


def echo(to, text):
    payload = {"object": "whatsapp_business_account", "entry": [{"changes": [{"field": "smb_message_echoes", "value": {
        "messaging_product": "whatsapp", "metadata": {"phone_number_id": PID},
        "message_echoes": [{"from": "961", "to": to, "id": "wamid.ECHO" + uuid.uuid4().hex, "type": "text", "text": {"body": text}}]}}]}]}
    t0 = time.time(); wa_post(payload); return settle(t0)


def order(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + G["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->latest("number")->first()?->only("id","number","status","payment_status","total","whish_external_id","paid_at"));')


def pay(ext, result="success", amount=None):
    url = f"http://127.0.0.1:8029/pay/{ext}?result={result}" + (f"&amount={amount}" if amount is not None else "")
    t0 = time.time()
    with urllib.request.urlopen(url, timeout=60) as r:
        out = json.loads(r.read())
    settle(t0, quiet=2.0, cap=20)
    return out, t0


def whish_order(phone):
    """Customer orders with buttons and picks Whish; returns the last bot messages."""
    say(phone, text="add 9 falafel")
    say(phone, button="co:start:")
    out = say(phone, text="Hamra, Makdessi street, building 5, floor 2")
    for _ in range(4):
        ids = [i for x in out for grp in re.findall(r"\[(?:BTN|LIST) ([^\]]+)\]", x) for i in [p.split("=")[0] for p in re.split(r" \| |,", grp)]]
        if "co:ok:" in ids:
            break
        pick = next((i for i in ids if "whish" in i), None)
        if not pick:
            break
        out = say(phone, button=pick)
    return out


suite("Whish (simulated): shop connects Whish")
code, b = api("PATCH", "/settings/profile", G["token"], {"whish_channel": "lab-channel", "whish_secret": "wrong-secret", "whish_sandbox": False})
print("  wrong secret ->", code, str(b)[:160])
check("a wrong Whish secret is refused", code == 422, (code, str(b)[:160]))
code, b = api("PATCH", "/settings/profile", G["token"], {"whish_channel": "lab-channel", "whish_secret": "lab-whish-secret", "whish_sandbox": False})
check("the right Whish details connect", code == 200 and (b.get("profile") or b).get("whish_connected") is True, (code, str(b)[:200]))
check("the secret is never sent back to the dashboard", "lab-whish-secret" not in json.dumps(b))
stored = tj('echo json_encode(App\\Models\\Merchant::find("' + G["id"] + '")->settings["whish"]["secret"] ?? null);')
check("the secret is stored encrypted", stored and stored != "lab-whish-secret", "plain")

suite("Whish (simulated): customer pays the exact amount")
c1 = "9617991" + RUN
out = whish_order(c1)
receipt = " ".join(out)
check("receipt offers Whish and shows the total", "Whish" in receipt and "13.25$" in receipt, receipt[:300])
out = say(c1, button="co:ok:")
o = order(c1)
check("the Whish order waits for the shop first (no link yet)", o["status"] == "placed" and o["payment_status"] == "pending" and not o["whish_external_id"], o)
check("no payment link before the shop accepts", not any("8029/pay" in x for x in out), out)
msgs = echo(c1, "✅")
o = order(c1)
link = [x for x in texts([m for m in msgs if m["body"].get("to", "").lstrip("+") == c1]) if "8029/pay/" in x]
check("after ✅ the customer gets the Whish link", o["status"] == "pending_payment" and link, (o, texts(msgs)))
created = [json.loads(l) for l in open(WLOG)][-5:]
mine = [r for r in created if r["path"].endswith("/payment/whish") and str(r["body"].get("externalId")) == str(o["whish_external_id"])]
check("the link asks Whish for exactly 13.25 USD", mine and mine[0]["body"]["amount"] == 13.25 and mine[0]["body"]["currency"] == "USD", mine)
res, t0 = pay(o["whish_external_id"])
o = order(c1)
told = texts([m for m in outbox(since=t0) if m["body"].get("to", "").lstrip("+") == c1])
check("Whish calls back and the app accepts the callback", res["callback"] == 200, res)
check("paid → order confirmed, payment marked paid", o["status"] == "confirmed" and o["payment_status"] == "paid" and o["paid_at"], o)
check("the customer is told the payment arrived", len(told) >= 1, told)
res2, t1 = pay(o["whish_external_id"])
again = texts([m for m in outbox(since=t1) if m["body"].get("to", "").lstrip("+") == c1])
check("Whish calling back twice: no second 'paid' message", again == [], again)

suite("Whish (simulated): wrong amount, no payment, late payment")
c2 = "9617992" + RUN
whish_order(c2); say(c2, button="co:ok:"); echo(c2, "✅")
o = order(c2)
res, t0 = pay(o["whish_external_id"], amount=1.00)
check("paying 1.00 instead of 13.25 does not count", order(c2)["payment_status"] == "pending" and order(c2)["status"] == "pending_payment", order(c2))
n = tj('echo json_encode(DB::table("jobs")->where("payload","like","%CheckWhishPayment%")->where("payload","like","%' + o["id"] + '%")->where("payload","like","%final\\\\\\";b:1%")->update(["available_at"=>now()->subSecond()->timestamp]));')
t0 = time.time(); settle(t0, quiet=2.0, cap=20)
o = order(c2)
check("end of the payment window: unpaid order cancelled", n >= 1 and o["status"] == "cancelled" and o["payment_status"] == "failed", (n, o))
told = texts([m for m in outbox(since=t0) if m["body"].get("to", "").lstrip("+") == c2])
check("the customer is told it was cancelled", told, told)
res, t0 = pay(o["whish_external_id"], amount=13.25)
o = order(c2)
check("a payment after the cancellation is flagged for refund", o["payment_status"] == "refund_due" and o["status"] == "cancelled", o)
check("the order is not revived after a late payment", o["status"] == "cancelled")

suite("Whish (simulated): failed payment")
c3 = "9617993" + RUN
whish_order(c3); say(c3, button="co:ok:"); echo(c3, "✅")
o = order(c3)
res, t0 = pay(o["whish_external_id"], result="failure")
o = order(c3)
check("a failed payment never marks the order paid", o["payment_status"] != "paid", o)
print("  after a failed payment:", o["status"], o["payment_status"])

suite("Whish (simulated): ❌ before paying")
c4 = "9617994" + RUN
whish_order(c4); say(c4, button="co:ok:")
echo(c4, "❌")
o = order(c4)
check("a refused Whish order never gets a payment link", o["status"] == "rejected" and not o["whish_external_id"], o)
