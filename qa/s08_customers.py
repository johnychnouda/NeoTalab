"""Customer scenarios across shop types: languages, pickup, minimum order, closed shop, blocked number,
asking for a person, changing their mind, returning customer. Stand-in AI grammar in the lab."""
import re
from lib import *

s = state(); RUN = str(int(time.time()))[-5:]
G = s["shops"]["grocer"]; P = s["shops"]["pharm"]; R = s["shops"]["rest"]


def say(shop, frm, **kw):
    code, mid, t0 = wa_msg(shop["phone_id"], frm, **kw)
    return texts([x for x in settle(t0) if x["body"].get("to", "").lstrip("+") == frm])


def cart(shop, phone):
    return tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("phone","+' + phone + '")->first();'
              '$cv=$c?App\\Models\\Conversation::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null;'
              '$ct=$cv?App\\Models\\Cart::withoutGlobalScopes()->where("conversation_id",$cv->id)->where("status","open")->first():null;'
              'echo json_encode($ct?["subtotal"=>$ct->subtotal,"items"=>App\\Models\\CartItem::withoutGlobalScopes()->where("cart_id",$ct->id)->get()->map(fn($i)=>[$i->product_id,$i->quantity,$i->line_total])]:null);')


suite("Customers: languages")
c = "96177100" + RUN
out = say(G, c + "1", text="مرحبا")
check("Arabic greeting answered (Arabic text)", out and re.search(r"[؀-ۿ]", " ".join(out)), out)
out = say(G, c + "2", text="bonjour")
check("French greeting answered", out, out)
out = say(G, c + "3", text="marhaba, shou fi?")
check("Arabizi greeting answered", out, out)
out = say(G, c + "1", text="قائمة")
check("Arabic menu request shows the list", any("mn:" in x for x in out), out)
out = say(G, c + "1", text="بدي 3 falafel")
ct = cart(G, c + "1")
check("Arabic 'بدي 3 falafel' → 3 in cart at 1.25 = 3.75", ct and ct["items"] and ct["items"][0][1] == 3 and ct["items"][0][2] == "3.75", (out, ct))

suite("Customers: grocery quantities, change of mind, minimum order")
g1 = c + "4"
say(G, g1, text="add 4 falafel")
say(G, g1, text="add 2 shared item")
ct = cart(G, g1)
print("  cart:", ct)
check("4 × 1.25 + 2 × 2.83 = 10.66", ct and ct["subtotal"] == "10.66", ct)
say(G, g1, text="remove shared item")
ct = cart(G, g1)
check("remove leaves 4 falafel = 5.00", ct and ct["subtotal"] == "5.00" and len(ct["items"]) == 1, ct)
out = say(G, g1, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
check("5.00$ under the 10.00$ Hamra minimum is refused with the minimum stated", any("10.00$" in x for x in out), out)
n = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + G["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + g1 + '"))->count());')
check("no order created under the minimum", n == 0, n)
say(G, g1, text="add 5 falafel")
out = say(G, g1, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
o = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + G["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + g1 + '"))->latest()->first()?->only("subtotal","delivery_fee","total","status"));')
check("9 × 1.25 = 11.25 + 2.00 delivery = 13.25", o and (o["subtotal"], o["delivery_fee"], o["total"]) == ("11.25", "2.00", "13.25"), (out, o))
check("grocery (no staff) accepts the cash order itself", o and o["status"] == "confirmed", o)
out = say(G, g1, text="add 1000 falafel")
ct = cart(G, g1)
print("  1000 falafel ->", out, ct)
check("absurd quantity is capped (never 1000 in one line)", not ct or all(i[1] < 1000 for i in ct["items"]), ct)

suite("Customers: unknown and unavailable items, nonsense")
out = say(P, c + "5", text="add 2 unicorn pills")
check("unknown item: polite 'couldn't find'", out and not cart(P, c + "5"), out)
code, b = api("PATCH", f"/products/{P['p1']}", P["token"], {"is_available": False})
out = say(P, c + "5", text="add 1 falafel")
check("sold-out item not added", not cart(P, c + "5") or not cart(P, c + "5")["items"], (out, cart(P, c + "5")))
api("PATCH", f"/products/{P['p1']}", P["token"], {"is_available": True})
out = say(P, c + "5", text="😀😀😀")
check("emoji-only message answered", out, out)
out = say(P, c + "5", text="<script>alert(1)</script> ' OR 1=1 --")
check("injection-looking text answered normally", out, out)

suite("Customers: pickup-only pharmacy")
code, b = api("PATCH", "/settings/profile", P["token"], {"fulfillment": "pickup"})
check("pharmacy switches to pickup only", code == 200, (code, str(b)[:200]))
p1 = c + "6"
say(P, p1, text="add 2 shared item")
out = say(P, p1, button="co:start:")
print("  pharmacy checkout:", out)
check("pickup-only checkout never asks for a location", not any("location" in x.lower() for x in out), out)

suite("Customers: shop closed by staff, then reopened")
STAFF = "96171222002"
code, mid, t0 = wa_msg(R["phone_id"], STAFF, text="close"); settle(t0)
mode = tj('echo json_encode(App\\Models\\Merchant::find("' + R["id"] + '")->settings["mode"]??null);')
check("staff 'close' closes the shop", mode == "closed", mode)
out = say(R, c + "7", text="hi")
check("customer told the shop is closed", any("closed" in x.lower() for x in out), out)
code, mid, t0 = wa_msg(R["phone_id"], STAFF, text="open"); settle(t0)
out = say(R, c + "7", text="hi")
check("after 'open' the menu is back", any("mn:" in x for x in out), out)

suite("Customers: blocked number, person request")
b1 = c + "8"
say(R, b1, text="hi")
cid = tj('echo json_encode(App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + R["id"] + '")->where("phone","+' + b1 + '")->value("id"));')
code, _ = api("POST", f"/customers/{cid}/block", R["token"])
out = say(R, b1, text="hi")
check("blocked number gets no reply", code == 200 and not out, (code, out))
api("POST", f"/customers/{cid}/unblock", R["token"])
out = say(R, b1, text="hi")
check("unblocked number answered again", out, out)
code, mid, t0 = wa_msg(R["phone_id"], b1, text="I want to talk to a person")
allout = settle(t0)
mine = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == b1])
staff = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == STAFF])
check("person request: customer told a team member will reply", any("team" in x.lower() for x in mine), mine)
check("person request: staff alerted with a resume button", any("stf:resume:" in x for x in staff), staff)
out = say(R, b1, text="add 1 zaatar manousheh")
check("bot stays quiet (short hold note at most) while paused", not cart(R, b1) or not cart(R, b1)["items"], out)
code, mid, t0 = wa_msg(R["phone_id"], STAFF, button=f"stf:resume:{cid}"); settle(t0)
out = say(R, b1, text="hi")
check("after staff taps resume, bot answers again", any("mn:" in x for x in out), out)

suite("Customers: returning customer")
out = say(G, g1, text="hi")
check("returning customer greeted with menu", any("mn:" in x for x in out), out)
say(G, g1, text="add 9 falafel")
out = say(G, g1, button="co:start:")
print("  returning checkout:", out)
check("saved address offered at checkout", any("Bliss" in x for x in out), out)
