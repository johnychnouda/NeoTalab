"""Workflow 2: a delivery order from first message to delivered, across customer, staff, driver,
plus interference from a second customer and a second driver. WhatsApp is the lab stand-in."""
import re
from lib import *

s = state(); r = s["shops"]["rest"]; PID = r["phone_id"]
STAFF, DRIVER, DRIVER2 = "96171222002", "96171222001", "96171222009"
RUN = str(int(time.time()))[-5:]
C1, C2 = "9617610" + RUN, "9617620" + RUN


def say(frm, wait=0.4, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    allout = settle(t0)
    mine = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == frm])
    return code, mine, allout


def buttons(out):
    ids = []
    for x in out:
        for grp in re.findall(r"\[(?:BTN|LIST) ([^\]]+)\]", x):
            ids += [p.split("=")[0] for p in re.split(r" \| |,", grp)]
    return ids


def order_of(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")'
              '->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->latest()->first()?->only("id","number","status","subtotal","delivery_fee","total","driver_id","accepted_at","payment_status","fulfillment"));')


suite("W2 delivery journey: team gets ready")
# The second driver is created by the shop owner in the dashboard.
code, b = api("POST", "/drivers", r["token"], {"name": "QA Driver Two", "phone": "+" + DRIVER2, "role": "driver"})
check("owner adds a second driver", code in (201, 422), code)
code, out, _ = say(STAFF, text="hi")
check("staff number gets a staff reply (not the customer bot)", out and "menu" not in " ".join(out).lower()[:40], out)
code, out, _ = say(DRIVER, text="start")
check("driver starts shift", out, out)
st = tj('echo json_encode(App\\Models\\Driver::withoutGlobalScopes()->where("phone","+' + DRIVER + '")->value("status"));')
check("driver is on duty in the database", st == "on_duty", st)
say(DRIVER2, text="start")

suite("W2 delivery journey: customer orders with size + extra")
code, out, _ = say(C1, text="hi", name="QA Delivery Customer")
check("greeting comes with the menu list", any("mn:" in x for x in out), out)
code, out, _ = say(C1, list_id=f"mn:item:{r['p1']}")
check("size question shows Large 4.00$", any("Large 4.00$" in x for x in out), out)
code, out, _ = say(C1, list_id=f"mn:var:{r['variant']}")
check("extras offered with price", any("mn:mod:" in x for x in out), out)
code, out, _ = say(C1, list_id=f"mn:mod:{r['mod']}")
code, out, _ = say(C1, list_id="mn:done")
check("quantity buttons", "mn:qty:2" in buttons(out), out)
code, out, _ = say(C1, button="mn:qty:2")
check("cart shows 2 items · 9.50$ (2 × (4.00 + 0.75))", any("9.50$" in x for x in out), out)

code, out, _ = say(C1, button="co:start:")
print("  checkout start:", out)
for step in range(6):
    if any("Confirm order" in x for x in out):
        break
    ids = buttons(out)
    if any("location" in x.lower() or "address" in x.lower() for x in out) and not ids:
        code, out, _ = say(C1, text="Hamra, Bliss street, building 12, floor 3")
    elif ids:
        pick = next((i for i in ids if i.startswith("co:zone:")), None) or next((i for i in ids if "cash" in i or i.startswith("co:pay:")), None) or ids[0]
        code, out, _ = say(C1, button=pick)
    else:
        code, out, _ = say(C1, text="Hamra, Bliss street, building 12, floor 3")
    print(f"  step {step}:", out)
receipt = " ".join(out)
check("receipt subtotal 9.50$", "Subtotal: 9.50$" in receipt, receipt)
check("receipt delivery Hamra 1.50$", "1.50$" in receipt, receipt)
check("receipt total 11.00$", "Total: 11.00$" in receipt, receipt)

code, out, allout = say(C1, button="co:ok:", wait=0.8)
o = order_of(C1)
check("order saved once, waiting for the shop (staff reachable)", o and o["status"] == "placed", o)
check("server totals: 9.50 + 1.50 = 11.00", o and (o["subtotal"], o["delivery_fee"], o["total"]) == ("9.50", "1.50", "11.00"), o)
staff_msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == STAFF])
check("staff got a ticket with Accept", any(f"stf:accept:{o['id']}" in x for x in staff_msgs), staff_msgs)
n_orders = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + C1 + '"))->count());')
check("exactly one order for the customer", n_orders == 1, n_orders)

suite("W2 interference before acceptance")
code, out, _ = say(C1, button="co:ok:")  # double tap on Confirm
n2 = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + C1 + '"))->count());')
check("double tap on Confirm does not create a second order", n2 == 1, n2)
code, out, _ = say(C2, button=f"stf:accept:{o['id']}", name="QA Other Customer")
check("a customer sending the staff Accept id does not accept", order_of(C1)["status"] == "placed", order_of(C1))
code, out, _ = say(C2, button=f"trk:{o['id']}")
check("another customer cannot track this order", not any(f"#{o['number']}" in x for x in out), out)
code, out, _ = say(C2, button=f"cx:yes:{o['id']}")
check("another customer cannot cancel this order", order_of(C1)["status"] == "placed", out)
code, out, _ = say(DRIVER2, button=f"drv:delivered:{o['id']}")
check("a driver cannot complete an order that is not theirs", order_of(C1)["status"] == "placed", out)

suite("W2 staff accepts, driver delivers")
code, out, allout = say(STAFF, button=f"stf:accept:{o['id']}", wait=0.8)
o = order_of(C1)
check("staff Accept confirms the order", o["status"] == "confirmed" and o["accepted_at"], o)
cust_msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == C1])
check("customer told the order is confirmed", any(f"#{o['number']}" in x for x in cust_msgs), cust_msgs)
drv = tj('echo json_encode(App\\Models\\Driver::withoutGlobalScopes()->find("' + (o["driver_id"] or "x") + '")?->only("name","active_order_id"));')
check("order handed to an on-duty driver", drv and drv["active_order_id"] == o["id"], (o, drv))
drv_phone = DRIVER if drv and drv["name"] == "QA Driver Karim" else DRIVER2
other_drv = DRIVER2 if drv_phone == DRIVER else DRIVER
drv_msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == drv_phone])
check("driver got the delivery card with Picked up", any(f"drv:picked_up:{o['id']}" in x for x in drv_msgs), drv_msgs)
check("driver card states cash to collect 11.00$", any("11.00$" in x for x in drv_msgs), drv_msgs)
code, out, _ = say(other_drv, button=f"drv:picked_up:{o['id']}")
check("the other driver cannot pick it up", order_of(C1)["status"] == "confirmed", out)
code, out, _ = say(STAFF, button=f"stf:accept:{o['id']}")
check("second Accept changes nothing", order_of(C1)["status"] == "confirmed", out)
code, out, allout = say(drv_phone, button=f"drv:picked_up:{o['id']}", wait=0.8)
o = order_of(C1)
check("Picked up → picked_up", o["status"] == "picked_up", o)
cust_msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == C1])
check("customer told it is on the way", cust_msgs, cust_msgs)
code, out, allout = say(drv_phone, button=f"drv:delivered:{o['id']}", wait=0.8)
o = order_of(C1)
check("Delivered → delivered", o["status"] == "delivered", o)
free = tj('echo json_encode(App\\Models\\Driver::withoutGlobalScopes()->where("phone","+' + drv_phone + '")->first()->only("active_order_id","availability"));')
# The driver may be given an older waiting order from an earlier run (the queue hands it over on purpose),
# so the check is that they are no longer on THIS order.
check("driver is no longer on this order", free["active_order_id"] != o["id"], free)
code, out, _ = say(drv_phone, button=f"drv:delivered:{o['id']}")
code, out, _ = say(STAFF, button=f"stf:reject:{o['id']}")
check("delivered order cannot be refused afterwards", order_of(C1)["status"] == "delivered", out)
code, out, _ = say(C1, text="cancel my order")
check("customer cannot cancel a delivered order", order_of(C1)["status"] == "delivered", out)

suite("W2 records agree after delivery")
cu = tj('echo json_encode(App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")->where("phone","+' + C1 + '")->first()->only("total_orders","total_spent","name"));')
check("customer record: 1 order, 11.00 spent", cu["total_orders"] == 1 and float(cu["total_spent"]) == 11.0, cu)
code, b = api("GET", "/customers", r["token"])
check("customer appears in the shop's customer list", C1 in json.dumps(b), code)
code, b = api("GET", "/analytics/dashboard", r["token"])
print("  analytics:", json.dumps(b)[:600])
items = tj('echo json_encode(App\\Models\\OrderItem::withoutGlobalScopes()->where("order_id","' + o["id"] + '")->get(["product_name","quantity","unit_price","item_total","modifiers"]));')
check("order line: Falafel (Large) ×2 at 4.75 = 9.50 with Cheese", len(items) == 1 and items[0]["quantity"] == 2 and items[0]["unit_price"] == "4.75" and items[0]["item_total"] == "9.50" and "Cheese" in items[0]["modifiers"], items)

suite("W2 duplicate webhook delivery")
mid = "wamid.DUP" + uuid.uuid4().hex
t0 = time.time()
for _ in range(3):
    wa_msg(PID, C2, text="hi", mid=mid)
settle(t0)
n = len(outbox(to="+" + C2, since=t0)) + len(outbox(to=C2, since=t0))
check("same message delivered 3 times is answered once", n == 1, n)
