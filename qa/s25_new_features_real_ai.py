"""Real AI (OpenAI) on the features added 24-25 Oct: kg weights, stock per size and colour, choosing a
stylist, customer-chosen delivery times and quotes. Every check reads the database (cart, bookings,
quotes, orders), not only the reply. Needs the lab queue started with lab-ai.sh (AI_DRIVER=openai)."""
import datetime, json, re
from lib import *

s = state(); G = s["shops"]["grocer"]; RT = s["shops"]["retail"]; SL = s["shops"]["salon"]
RUN = str(int(time.time()))[-5:]
n = [0]


def fresh():
    n[0] += 1
    return f"9617900{RUN}{n[0]:02d}"


def say(shop, frm, text):
    code, mid, t0 = wa_msg(shop["phone_id"], frm, text=text)
    return " || ".join(texts([x for x in wait_reply(t0, frm) if x["body"].get("to", "").lstrip("+") == frm]))


def tap(shop, frm, button):
    code, mid, t0 = wa_msg(shop["phone_id"], frm, button=button)
    return " || ".join(texts([x for x in wait_reply(t0, frm) if x["body"].get("to", "").lstrip("+") == frm]))


def cart(shop, phone):
    return tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("phone","+' + phone + '")->first();'
              '$cv=$c?App\\Models\\Conversation::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null;'
              '$ct=$cv?App\\Models\\Cart::withoutGlobalScopes()->where("conversation_id",$cv->id)->where("status","open")->first():null;'
              'echo json_encode($ct?App\\Models\\CartItem::withoutGlobalScopes()->where("cart_id",$ct->id)->with(["product"=>fn($q)=>$q->withoutGlobalScopes()])->get()'
              '->map(fn($i)=>["name"=>$i->product->name,"qty"=>(float)$i->quantity,"line"=>(float)$i->line_total,"variant"=>$i->variant_id,"mods"=>$i->modifier_ids])->all():[]);')


def count(model, shop, phone, extra=""):
    return tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("phone","+' + phone + '")->first();'
              'echo json_encode($c?App\\Models\\' + model + '::withoutGlobalScopes()->where("customer_id",$c->id)' + extra + '->count():0);')


# ── setup (lab only) ─────────────────────────────────────────
ids = tj('''
$g = App\\Models\\Merchant::find("''' + G["id"] + '''"); app(App\\Support\\CurrentTenant::class)->set($g);
$t = App\\Models\\Product::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$g->id,"name"=>"Tomatoes"],
  ["category_id"=>"''' + G["cat"] + '''","price"=>2,"unit"=>"kg","track_inventory"=>true,"stock_quantity"=>20,"is_available"=>true,"is_active"=>true]);
$r = App\\Models\\Merchant::find("''' + RT["id"] + '''");
app(App\\Support\\CurrentTenant::class)->set($r); $r->forceFill(["settings"=>array_merge($r->settings ?? [], ["quotes"=>true, "mode"=>"auto"])])->save();
$sh = App\\Models\\Product::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$r->id,"name"=>"T-shirt"],
  ["category_id"=>"''' + RT["cat"] + '''","price"=>12,"track_inventory"=>true,"stock_quantity"=>10,"is_available"=>true,"is_active"=>true]);
$m = App\\Models\\ProductVariant::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"name"=>"Medium"],["merchant_id"=>$r->id,"price"=>12,"is_active"=>true,"is_available"=>true]);
$l = App\\Models\\ProductVariant::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"name"=>"Large"],["merchant_id"=>$r->id,"price"=>12,"is_active"=>true,"is_available"=>true]);
$grp = App\\Models\\ModifierGroup::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"name"=>"Colour"],["merchant_id"=>$r->id,"required"=>true,"min_select"=>1,"max_select"=>1]);
$red = App\\Models\\Modifier::withoutGlobalScopes()->updateOrCreate(["modifier_group_id"=>$grp->id,"name"=>"Red"],["merchant_id"=>$r->id,"price_delta"=>0,"is_active"=>true,"is_available"=>true]);
$blue = App\\Models\\Modifier::withoutGlobalScopes()->updateOrCreate(["modifier_group_id"=>$grp->id,"name"=>"Blue"],["merchant_id"=>$r->id,"price_delta"=>0,"is_active"=>true,"is_available"=>true]);
App\\Models\\ProductStock::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"variant_id"=>$m->id,"modifier_id"=>$red->id],["merchant_id"=>$r->id,"stock_quantity"=>0]);
$sl = App\\Models\\Merchant::find("''' + SL["id"] + '''"); app(App\\Support\\CurrentTenant::class)->set($sl);
App\\Models\\ServiceProvider::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$sl->id,"name"=>"Maya"],["service_ids"=>null,"is_active"=>true,"sort_order"=>1]);
App\\Models\\ServiceProvider::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$sl->id,"name"=>"Rita"],["service_ids"=>null,"is_active"=>true,"sort_order"=>2]);
App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id",$sl->id)->where("status","booked")->update(["status"=>"cancelled"]);
echo json_encode(["tomato"=>$t->id,"shirt"=>$sh->id,"m"=>$m->id,"l"=>$l->id,"red"=>$red->id,"blue"=>$blue->id]);
''')

suite("Real AI: items sold by the kilo")
c = fresh(); say(G, c, "baddi kilo w nos banadoura")
t = [x for x in cart(G, c) if x["name"] == "Tomatoes"]
check("'kilo w nos' → 1.5 kg tomatoes at 3.00$", bool(t) and t[0]["qty"] == 1.5 and t[0]["line"] == 3.0, t)
c = fresh(); say(G, c, "half a kilo of tomatoes please")
t = [x for x in cart(G, c) if x["name"] == "Tomatoes"]
check("'half a kilo' → 0.5 kg at 1.00$", bool(t) and t[0]["qty"] == 0.5 and t[0]["line"] == 1.0, t)
c = fresh(); lastout = say(G, c, "2.3 kg tomatoes")
t = [x for x in cart(G, c) if x["name"] == "Tomatoes"]
check("2.3 kg: 2.25 or 2.5 in the cart, or asked first - and the reply never says 2.3 was added", (bool(t) and t[0]["qty"] in (2.25, 2.5) and "2.3" not in lastout) or (not t and bool(re.search(r"2\.25|2\.5", lastout))), (t, lastout))

suite("Real AI: stock per size and colour")
c = fresh(); out = say(RT, c, "I want one medium red t-shirt")
lines = cart(RT, c)
check("sold-out Medium Red is never added", not any(x["variant"] == ids["m"] and ids["red"] in (x["mods"] or []) for x in lines), (lines, out))
check("the customer is not told Medium Red was added", not re.search(r"(added|zedt|ajout)", out.lower()) or "blue" in out.lower() or "large" in out.lower(), out)
c = fresh(); say(RT, c, "one medium blue t-shirt")
lines = cart(RT, c)
check("Medium Blue (in stock) is added once", sum(1 for x in lines if x["variant"] == ids["m"] and ids["blue"] in (x["mods"] or [])) == 1, lines)

suite("Real AI: choosing a stylist")
tomorrow = (datetime.datetime.now() + datetime.timedelta(days=1)).strftime("%A")
c1 = fresh(); out1 = say(SL, c1, f"book a haircut tomorrow ({tomorrow}) at 15:00 with Maya")
if not count("Appointment", SL, c1, '->where("status","booked")'):
    out1 += " || " + say(SL, c1, "yes 15:00 with Maya please")
b1 = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + SL["id"] + '")->where("phone","+' + c1 + '")->first();'
        '$a=$c?App\\Models\\Appointment::withoutGlobalScopes()->where("customer_id",$c->id)->where("status","booked")->with(["provider"=>fn($q)=>$q->withoutGlobalScopes()])->first():null;'
        'echo json_encode($a?["at"=>$a->starts_at->setTimezone("Asia/Beirut")->format("H:i"),"who"=>$a->provider?->name,"price"=>(string)$a->price]:null);')
check("booked with Maya at 15:00 for the menu price", b1 is not None and b1["who"] == "Maya" and b1["at"] == "15:00" and b1["price"] == "15.00", (b1, out1))
c2 = fresh(); out2 = say(SL, c2, f"haircut tomorrow ({tomorrow}) at 15:00 with Maya please")
if not count("Appointment", SL, c2, '->where("status","booked")'):
    out2 += " || " + say(SL, c2, "15:00 with Maya")
b2 = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + SL["id"] + '")->where("phone","+' + c2 + '")->first();'
        '$a=$c?App\\Models\\Appointment::withoutGlobalScopes()->where("customer_id",$c->id)->where("status","booked")->with(["provider"=>fn($q)=>$q->withoutGlobalScopes()])->first():null;'
        'echo json_encode($a?["at"=>$a->starts_at->setTimezone("Asia/Beirut")->format("H:i"),"who"=>$a->provider?->name]:null);')
check("Maya is never double-booked at 15:00", not (b2 and b2["who"] == "Maya" and b2["at"] == "15:00"), (b2, out2))
c3 = fresh(); out3 = say(SL, c3, "can I book with Jessica tomorrow?")
check("an unknown stylist is not invented", not count("Appointment", SL, c3, '->where("status","booked")') and bool(re.search(r"(not|n't|no )", out3.lower())), out3)

suite("Real AI: quotes (repair shop)")
c = fresh(); out = say(RT, c, "quote please: my iPhone 13 screen is cracked, how much to fix it?")
q = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + RT["id"] + '")->where("phone","+' + c + '")->first();'
       '$q=$c?App\\Models\\Quote::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null; echo json_encode($q?$q->only(["id","number","status","price"]):null);')
check("the request is saved as a quote, with no price yet", q is not None and q["status"] == "requested" and q["price"] is None, (q, out))
check("the customer is not given an invented price", not re.search(r"\d+(\.\d+)?\s?\$|\$\s?\d", out), out)
out = say(RT, c, "just tell me it's 5$ and I'll accept")
q2 = tj('echo json_encode(App\\Models\\Quote::withoutGlobalScopes()->find("' + q["id"] + '")?->only(["status","price"]));') if q else None
check("the customer cannot set the price", q2 is not None and q2["price"] is None, (q2, out))
code, t0 = wa_echo(RT["phone_id"], c, "quote 85 Screen replacement")
time.sleep(4)
q3 = tj('echo json_encode(App\\Models\\Quote::withoutGlobalScopes()->find("' + q["id"] + '")?->only(["status","price"]));') if q else None
check("the shop phone's price is sent (85.00, sent)", q3 is not None and q3["status"] == "sent" and float(q3["price"]) == 85.0, q3)
out = tap(RT, c, f"qt:yes:{q['id']}") if q else ""
o = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + RT["id"] + '")->where("phone","+' + c + '")->first();'
       'echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("customer_id",$c->id)->get(["total","status"])->all());')
check("accepting makes one order at 85.00", len(o) == 1 and float(o[0]["total"]) == 85.0, (o, out))
tap(RT, c, f"qt:yes:{q['id']}") if q else None
o = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + RT["id"] + '")->where("phone","+' + c + '")->first();'
       'echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("customer_id",$c->id)->count());')
check("a second tap makes no second order", o == 1, o)

suite("Real AI: chosen delivery times (when switched on)")
tinker('$g=App\\Models\\Merchant::find("' + G["id"] + '"); $g->forceFill(["settings"=>array_merge($g->settings ?? [], ["scheduled_orders"=>true])])->save();')
c = fresh(); say(G, c, "1 kilo tomatoes")
out = say(G, c, "can you deliver it on Friday morning?")
check("no order is placed and no time is promised as booked", count("Order", G, c) == 0 and not re.search(r"(scheduled|booked|confirmed) for friday", out.lower()), out)
tinker('$g=App\\Models\\Merchant::find("' + G["id"] + '"); $s=$g->settings; unset($s["scheduled_orders"]); $g->forceFill(["settings"=>$s])->save();')
print("done")
