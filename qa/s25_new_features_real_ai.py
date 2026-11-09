"""Real AI (OpenAI) on the features added 24-25 Oct: kg weights, sizes and colours and
customer-chosen delivery times. Every check reads the database (cart, orders), not only the reply. Needs the lab queue started with lab-ai.sh (AI_DRIVER=openai)."""
import datetime, json, re
from lib import *

s = state(); G = s["shops"]["grocer"]; RT = s["shops"]["retail"]
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
  ["category_id"=>"''' + G["cat"] + '''","price"=>2,"unit"=>"kg","is_available"=>true,"is_active"=>true]);
$r = App\\Models\\Merchant::find("''' + RT["id"] + '''");
app(App\\Support\\CurrentTenant::class)->set($r); $r->forceFill(["settings"=>array_merge($r->settings ?? [], ["mode"=>"auto"])])->save();
$sh = App\\Models\\Product::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$r->id,"name"=>"T-shirt"],
  ["category_id"=>"''' + RT["cat"] + '''","price"=>12,"is_available"=>true,"is_active"=>true]);
$m = App\\Models\\ProductVariant::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"name"=>"Medium"],["merchant_id"=>$r->id,"price"=>12,"is_active"=>true,"is_available"=>true]);
$l = App\\Models\\ProductVariant::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"name"=>"Large"],["merchant_id"=>$r->id,"price"=>12,"is_active"=>true,"is_available"=>true]);
$grp = App\\Models\\ModifierGroup::withoutGlobalScopes()->updateOrCreate(["product_id"=>$sh->id,"name"=>"Colour"],["merchant_id"=>$r->id,"required"=>true,"min_select"=>1,"max_select"=>1]);
$red = App\\Models\\Modifier::withoutGlobalScopes()->updateOrCreate(["modifier_group_id"=>$grp->id,"name"=>"Red"],["merchant_id"=>$r->id,"price_delta"=>0,"is_active"=>true,"is_available"=>true]);
$blue = App\\Models\\Modifier::withoutGlobalScopes()->updateOrCreate(["modifier_group_id"=>$grp->id,"name"=>"Blue"],["merchant_id"=>$r->id,"price_delta"=>0,"is_active"=>true,"is_available"=>true]);
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
check("2.3 kg: 2.25 or 2.5 in the cart, or asked first - and the reply never says 2.3 was added", (bool(t) and t[0]["qty"] in (2.25, 2.5) and "2.3" not in lastout) or (not t and "2.3" not in lastout and bool(re.search(r"2\.25|2\.5|increment|quarter|weight|quantity", lastout, re.I))), (t, lastout))

suite("Real AI: sizes and colours")
c = fresh(); out = say(RT, c, "I want one medium red t-shirt")
lines = cart(RT, c)
check("Medium Red is added once (no stock counting: the shop confirms availability)", sum(1 for x in lines if x["variant"] == ids["m"] and ids["red"] in (x["mods"] or [])) == 1, (lines, out))
c = fresh(); say(RT, c, "one medium blue t-shirt")
lines = cart(RT, c)
check("Medium Blue is added once", sum(1 for x in lines if x["variant"] == ids["m"] and ids["blue"] in (x["mods"] or [])) == 1, lines)

suite("Real AI: chosen delivery times (when switched on)")
tinker('$g=App\\Models\\Merchant::find("' + G["id"] + '"); $g->forceFill(["settings"=>array_merge($g->settings ?? [], ["scheduled_orders"=>true])])->save();')
c = fresh(); say(G, c, "1 kilo tomatoes")
out = say(G, c, "can you deliver it on Friday morning?")
check("no order is placed and no time is promised as booked", count("Order", G, c) == 0 and not re.search(r"(scheduled|booked|confirmed) for friday", out.lower()), out)
tinker('$g=App\\Models\\Merchant::find("' + G["id"] + '"); $s=$g->settings; unset($s["scheduled_orders"]); $g->forceFill(["settings"=>$s])->save();')
print("done")
