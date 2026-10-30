"""Whish-to-Whish payments (no Whish API): the customer pays the shop's Whish number with the order
number in the note, taps "I paid"; the shop confirms or refuses; an unpaid order expires. Also the
platform owner records subscription payments by Whish reference (unique, fee from the shop).
WhatsApp is the lab stand-in. Lab only."""
import time
from lib import *

s = state(); OWN = s["owner"]; r = s["shops"]["rest"]; PID = r["phone_id"]
STAFF = "96171222002"
RUN = str(int(time.time()))[-5:]


def say(frm, wait=0.4, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    allout = settle(t0)
    mine = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == frm])
    return code, mine, allout


def buttons(out):
    import re
    ids = []
    for x in out:
        for grp in re.findall(r"\[(?:BTN|LIST) ([^\]]+)\]", x):
            ids += [p.split("=")[0] for p in re.split(r" \| |,", grp)]
    return ids


def order_of(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")'
              '->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->latest()->first()?->only("id","number","status","payment_status","payment_method","total"));')


def place_whish_order(phone, name):
    say(phone, text="hi", name=name)
    say(phone, list_id=f"mn:item:{r['p1']}")
    say(phone, list_id=f"mn:var:{r['variant']}")
    say(phone, list_id=f"mn:mod:{r['mod']}")
    say(phone, list_id="mn:done")
    say(phone, button="mn:qty:2")
    code, out, _ = say(phone, button="co:start:")
    for _ in range(8):
        if any("Confirm order" in x for x in out):
            break
        ids = buttons(out)
        if "co:pay:whish" in ids:
            out = say(phone, button="co:pay:whish")[1]
        elif "co:cur:USD" in ids:
            out = say(phone, button="co:cur:USD")[1]
        elif any(i.startswith("co:zone:") for i in ids):
            out = say(phone, button=next(i for i in ids if i.startswith("co:zone:")))[1]
        elif "co:ful:delivery" in ids:
            out = say(phone, button="co:ful:delivery")[1]
        elif "co:when:asap" in ids:
            out = say(phone, button="co:when:asap")[1]
        else:
            out = say(phone, text="Hamra, Bliss street, building 12, floor 3")[1]
    code, out, _ = say(phone, button="co:ok:")
    return order_of(phone)


suite("Whish transfer: the shop sets its Whish number")
code, b = api("PATCH", "/settings/profile", r["token"], {"whish_number": "70 123 456"})
check("shop saves its Whish number (digits only)", code == 200, (code, str(b)[:160]))
code, b = api("PATCH", "/settings/profile", r["token"], {"whish_number": "12"})
check("a too-short Whish number is refused", code == 422, (code, str(b)[:160]))
code, b = api("PATCH", "/settings/profile", r["token"], {"whish_number": "70123456"})
check("shop Whish number is set again", code == 200, (code, str(b)[:160]))

suite("Whish transfer: paid by the customer, confirmed by the shop")
C1 = "9617" + RUN + "01"
o1 = place_whish_order(C1, "QA Whish Customer A")
check("order waits for the shop (placed, payment pending)", o1 and o1["status"] == "placed" and o1["payment_method"] == "whish", o1)
code, out, allout = say(STAFF, button=f"stf:accept:{o1['id']}", wait=0.8)
o1 = order_of(C1)
check("accepting sends the Whish number and order number to the customer", o1["status"] == "pending_payment", o1)
msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == C1])
check("instructions show the shop's Whish number, the order number and a I-paid button",
      any("70123456" in x and str(o1["number"]) in x for x in msgs) and f"wh:paid:{o1['id']}" in buttons(msgs), msgs)

code, out, allout = say(C1, button=f"wh:paid:{o1['id']}", wait=0.8)
o1 = order_of(C1)
check("I paid moves the order to awaiting_check (not paid)", o1["payment_status"] == "awaiting_check" and o1["status"] == "pending_payment", o1)
staff_msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == STAFF])
check("shop gets the check with Received and Not received buttons",
      f"stf:wh_ok:{o1['id']}" in buttons(staff_msgs) and f"stf:wh_no:{o1['id']}" in buttons(staff_msgs), staff_msgs)

code, out, _ = say(C1, button=f"stf:wh_ok:{o1['id']}")
check("a customer cannot confirm a payment with a shop button", order_of(C1)["payment_status"] == "awaiting_check", out)
code, out, _ = say(STAFF, button=f"stf:wh_ok:{o1['id']}", wait=0.8)
o1 = order_of(C1)
check("the shop confirms: payment paid and order confirmed", o1["payment_status"] == "paid" and o1["status"] == "confirmed", o1)

suite("Whish transfer: refused by the shop")
C2 = "9617" + RUN + "02"
o2 = place_whish_order(C2, "QA Whish Customer B")
say(STAFF, button=f"stf:accept:{o2['id']}", wait=0.8)
say(C2, button=f"wh:paid:{o2['id']}", wait=0.8)
code, out, _ = say(STAFF, button=f"stf:wh_no:{o2['id']}", wait=0.8)
o2 = order_of(C2)
check("the shop refuses a payment it did not receive: order cancelled, payment failed",
      o2["status"] == "cancelled" and o2["payment_status"] == "failed", o2)
code, out, _ = say(STAFF, button=f"stf:wh_ok:{o2['id']}")
check("a refused payment cannot be confirmed later", order_of(C2)["status"] == "cancelled" and order_of(C2)["payment_status"] == "failed", out)

suite("Whish transfer: no payment in the window expires the order")
C3 = "9617" + RUN + "03"
o3 = place_whish_order(C3, "QA Whish Customer C")
say(STAFF, button=f"stf:accept:{o3['id']}", wait=0.8)
tj(f'(new \\App\\Jobs\\CheckWhishTransfer("{o3["id"]}"))->handle(app(\\App\\Services\\OrderConfirmation::class)); echo json_encode(true);')
o3 = order_of(C3)
check("unpaid at the end of the window: cancelled, payment failed", o3["status"] == "cancelled" and o3["payment_status"] == "failed", o3)
code, out, allout = say(C3, button=f"wh:paid:{o3['id']}", wait=0.8)
o3 = order_of(C3)
check("a late 'I paid' on the cancelled order is taken for checking (still cancelled)", o3["payment_status"] == "awaiting_check" and o3["status"] == "cancelled", o3)
code, out, _ = say(STAFF, button=f"stf:wh_ok:{o3['id']}", wait=0.8)
o3 = order_of(C3)
check("the shop received the late payment: refund flagged, order stays cancelled", o3["payment_status"] == "refund_due" and o3["status"] == "cancelled", o3)

suite("Subscriptions: the owner records a Whish payment")
code, b = api("PATCH", f"/admin/merchants/{r['id']}", OWN, {"monthlyFee": 25, "yearlyFee": 250})
check("owner sets the shop's fee", code == 200, (code, str(b)[:160]))
ref = f"WS-LAB-{RUN}"
code, b = api("POST", f"/admin/merchants/{r['id']}/payments", OWN, {"whish_reference": ref, "confirmed_check": True})
check("owner records the payment with its Whish reference", code == 201, (code, str(b)[:200]))
check("the recorded payment has the shop's fee as the amount", (b.get("payment") or {}).get("amount") == "25.00", b)
code, b = api("POST", f"/admin/merchants/{r['id']}/payments", OWN, {"whish_reference": ref, "confirmed_check": True})
check("the same reference cannot be recorded twice", code == 422, (code, str(b)[:160]))
code, b = api("PATCH", f"/admin/merchants/{r['id']}", OWN, {"lastPaymentAt": "2026-10-01"})
check("editing the shop cannot mark it paid", code == 422, (code, str(b)[:160]))
code, b = api("GET", f"/admin/merchants/{r['id']}/payments", OWN)
check("the payment list shows the reference", any(p.get("whish_reference") == ref for p in (b.get("payments") or [])), b)
