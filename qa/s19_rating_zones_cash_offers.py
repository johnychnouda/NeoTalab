"""Ratings after delivery, delivery areas drawn on the map, split cash in dollars + lira, offers with dates."""
import datetime, re
from lib import *

s = state(); R = s["shops"]["rest"]; W = s["shops"]["whole"]; G = s["shops"]["grocer"]
RUN = str(int(time.time()))[-5:]
STAFF = "96171222002"


def say(shop, frm, **kw):
    code, mid, t0 = wa_msg(shop["phone_id"], frm, **kw)
    return texts([x for x in settle(t0) if x["body"].get("to", "").lstrip("+") == frm])


def last_order(shop, phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->latest("number")->first()?->only("id","number","status","rating","subtotal","delivery_fee","total","total_lbp","cash_main","payment_currency","delivery_zone_name"));')


def cart_qty(shop, phone):
    return tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("phone","+' + phone + '")->first();'
              '$cv=$c?App\\Models\\Conversation::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null;'
              '$ct=$cv?App\\Models\\Cart::withoutGlobalScopes()->where("conversation_id",$cv->id)->where("status","open")->first():null;'
              'echo json_encode($ct?(int)App\\Models\\CartItem::withoutGlobalScopes()->where("cart_id",$ct->id)->sum("quantity"):0);')


def delivered(shop, phone):
    """Place a cash pickup-less delivery order on the grocery (bot accepts) and mark it delivered via staff path."""
    say(shop, phone, text="add 9 falafel")
    say(shop, phone, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
    o = last_order(shop, phone)
    tinker('app(App\\Support\\CurrentTenant::class)->set(App\\Models\\Merchant::find("' + shop["id"] + '")); app(App\\Services\\OrderService::class)->transition(App\\Models\\Merchant::find("' + shop["id"] + '"), "' + o["id"] + '", "delivered");')
    t0 = time.time(); return o, settle(t0, quiet=2.0, cap=20)


suite("Ratings after delivery")
tinker('$m=App\\Models\\Merchant::find("' + G["id"] + '"); $s=$m->settings; $s["rating_delay_mins"]=1; $m->forceFill(["settings"=>$s])->save();')
c1 = "9617701" + RUN
o, out = delivered(G, c1)
n = tj('echo json_encode(DB::table("jobs")->where("payload","like","%NotifyCustomerOfOrderStatus%")->where("payload","like","%' + o["id"] + '%")->update(["available_at"=>now()->subSecond()->timestamp]));')
t0 = time.time(); out = settle(t0, quiet=2.0, cap=20)
asked = [x for x in texts([m for m in outbox(since=o and 0) if m["body"].get("to", "").lstrip("+") == c1]) if re.search(r"1.{0,3}5|⭐", x)]
check("after delivery the customer is asked for a 1–5 rating", asked, texts([m for m in outbox(since=time.time() - 120) if m["body"].get("to", "").lstrip("+") == c1]))
out = say(G, c1, text="5")
check("'5' is saved as the rating and thanked", last_order(G, c1)["rating"] == 5 and out, (last_order(G, c1), out))
say(G, c1, text="4")
check("a second number does not overwrite the rating", last_order(G, c1)["rating"] == 5, last_order(G, c1))

c2 = "9617702" + RUN
o, _ = delivered(G, c2)
tinker('App\\Models\\Order::withoutGlobalScopes()->whereKey("' + o["id"] + '")->update(["rating_requested_at"=>now()]);')
out = say(G, c2, text="٢")
check("Arabic digit '٢' is a rating of 2", last_order(G, c2)["rating"] == 2, (last_order(G, c2), out))
print("  low-rating reply:", out)

c3 = "9617703" + RUN
o, _ = delivered(G, c3)
tinker('App\\Models\\Order::withoutGlobalScopes()->whereKey("' + o["id"] + '")->update(["rating_requested_at"=>now()]);')
say(G, c3, list_id=f"mn:item:{G['p1']}")
out = say(G, c3, text="2")
check("rating pending, but '2' typed while choosing a quantity is a quantity, not a rating",
      last_order(G, c3)["rating"] is None, (last_order(G, c3), cart_qty(G, c3), out))
print("  cart after typing 2:", cart_qty(G, c3), out)

suite("Delivery areas drawn on the map")
T = W["token"]
api("PATCH", "/settings/profile", T, {"fulfillment": "delivery"})
for z in (api("GET", "/settings/zones", T)[1] or {}).get("zones", []):
    api("DELETE", f"/settings/zones/{z['id']}", T)
shop = [33.8959, 35.4787]
code, b = api("POST", "/settings/zones", T, {"name": "Near", "deliveryFee": 1, "area": {"type": "circle", "center": shop, "radius": 1500}})
check("circle area saved", code == 201, (code, str(b)[:150]))
square = [[33.86, 35.45], [33.86, 35.53], [33.93, 35.53], [33.93, 35.45]]
code, b = api("POST", "/settings/zones", T, {"name": "Beirut", "deliveryFee": 3, "area": {"type": "polygon", "points": square}})
check("drawn shape saved", code == 201, (code, str(b)[:150]))
for bad, label in (({"type": "circle", "center": shop, "radius": 50}, "50 m circle"), ({"type": "polygon", "points": square[:2]}, "2-point shape"),
                   ({"type": "circle", "center": [200, 35], "radius": 1000}, "impossible latitude"), ("oops", "text instead of a shape")):
    code, _ = api("POST", "/settings/zones", T, {"name": "Bad", "deliveryFee": 1, "area": bad})
    check(f"bad area refused: {label}", code == 422, code)


def pin_order(phone, lat, lng):
    say(W, phone, text="add 3 falafel")
    say(W, phone, button="co:start:")
    out = say(W, phone, location=(lat, lng))
    return out


c4 = "9617704" + RUN
out = pin_order(c4, 33.8975, 35.4800)  # ~250 m from the shop: inside both, the small one wins
for _ in range(3):
    if any("Confirm order" in x for x in out):
        break
    ids = re.findall(r"(co:[a-z]+:[^ =|\]]*)", " ".join(out))
    out = say(W, c4, button="co:skip:") if "co:skip:" in ids else (say(W, c4, button=ids[0]) if ids else out)
r = " ".join(out)
check("pin near the shop: the smaller 'Near' area and its 1.00$ fee", "Near" in r and "1.00$" in r, r[:300])
c5 = "9617705" + RUN
out = pin_order(c5, 33.8700, 35.5100)  # inside the square only
for _ in range(3):
    if any("Confirm order" in x for x in out):
        break
    ids = re.findall(r"(co:[a-z]+:[^ =|\]]*)", " ".join(out))
    out = say(W, c5, button="co:skip:") if "co:skip:" in ids else (say(W, c5, button=ids[0]) if ids else out)
r = " ".join(out)
check("pin elsewhere in Beirut: the 'Beirut' area, 3.00$", "Beirut" in r and "3.00$" in r, r[:300])
c6 = "9617706" + RUN
out = pin_order(c6, 34.4367, 35.8497)  # Tripoli
check("pin outside every area: told the shop does not deliver there", out and not any("Confirm order" in x for x in out), out)
print("  outside reply:", out[:1])

suite("Split cash: dollars + lira at the shop's rate")
c7 = "9617707" + RUN
say(G, c7, text="add 9 falafel")  # 11.25 + 2.00 = 13.25
out = say(G, c7, text="confirm | Hamra | Bliss street, building 12 | cash | MIXED | 10 | delivery")
o = last_order(G, c7)
# 3.25 left × 89,500 = 290,875 → rounded up to the next 1,000 LL
check("13.25$ paid 10$ + rest in lira: 291,000 LL at 89,500", o and o["payment_currency"] == "MIXED" and float(o["cash_main"]) == 10 and o["total_lbp"] == 291000, (o, out))
c8 = "9617708" + RUN
say(G, c8, text="add 9 falafel")
say(G, c8, text="confirm | Hamra | Bliss street, building 12 | cash | MIXED | 50 | delivery")
o = last_order(G, c8)
check("a dollar part above the total is not a split (paid as one currency)", o and o["payment_currency"] != "MIXED", o)
c9 = "9617709" + RUN
say(G, c9, text="add 9 falafel")
say(G, c9, text="confirm | Hamra | Bliss street, building 12 | cash | LBP | | delivery")
o = last_order(G, c9)
check("all in lira: 13.25 × 89,500 = 1,185,875 → 1,186,000 LL", o and o["payment_currency"] == "LBP" and o["total_lbp"] == 1186000, o)

suite("Offers with dates")
today = datetime.date.today()
api("PATCH", f"/products/{G['p1']}", G["token"], {"offer_percent": 20, "offer_starts_on": today.isoformat(), "offer_ends_on": (today + datetime.timedelta(days=7)).isoformat()})
c10 = "9617710" + RUN
say(G, c10, text="add 10 falafel")  # 10 × 1.00 with the offer
api("PATCH", f"/products/{G['p1']}", G["token"], {"offer_percent": 20, "offer_starts_on": (today - datetime.timedelta(days=7)).isoformat(), "offer_ends_on": (today - datetime.timedelta(days=1)).isoformat()})
say(G, c10, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
o = last_order(G, c10)
check("offer ended while in the cart: charged the normal 12.50 (10 × 1.25)", o and o["subtotal"] == "12.50", o)
api("PATCH", f"/products/{G['p1']}", G["token"], {"offer_percent": 20, "offer_starts_on": (today + datetime.timedelta(days=1)).isoformat(), "offer_ends_on": (today + datetime.timedelta(days=5)).isoformat()})
c11 = "9617711" + RUN
say(G, c11, text="add 10 falafel")
say(G, c11, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
check("offer starting tomorrow is not applied today", last_order(G, c11)["subtotal"] == "12.50", last_order(G, c11))
api("PATCH", f"/products/{G['p1']}", G["token"], {"offer_percent": 20, "offer_starts_on": today.isoformat(), "offer_ends_on": today.isoformat()})
c12 = "9617712" + RUN
say(G, c12, text="add 10 falafel")
say(G, c12, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
check("offer valid today only: 20% off → 10.00", last_order(G, c12)["subtotal"] == "10.00", last_order(G, c12))
api("PATCH", f"/products/{G['p1']}", G["token"], {"offer_percent": None, "offer_starts_on": None, "offer_ends_on": None})
