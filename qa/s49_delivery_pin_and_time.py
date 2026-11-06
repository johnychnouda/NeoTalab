"""Delivery needs the location pin, and the customer is told the preparation first and the arrival when it is on its way.
Runs against the scale lab (./scale_env.sh python3 s49_delivery_pin_and_time.py): a customer who types an address is
asked for the pin again; the pin then a note; the receipt, the shop's accept message and the driver's pick-up message say
what they should; the same checkout is read in Arabic and French. WhatsApp is the lab stand-in, Google Maps is off in the lab
(the area's minutes are used), so the arrival minutes here are the backup ones."""
import re, subprocess
from lib import *

s = state(); r = s["scale"]["sc000"]; PID = r["pid"]; MID = r["id"]  # a scale-lab restaurant with a menu
RUN = str(int(time.time()))[-5:]
STAFF, DRIVER = "96171991" + RUN[-3:], "96171992" + RUN[-3:]
CUSTOMERS = {"en": "9617610" + RUN, "ar": "9617611" + RUN, "fr": "9617612" + RUN}
HI = {"en": "hi", "ar": "مرحبا", "fr": "bonjour"}
workers = [subprocess.Popen([LABSH, "php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--timeout=75"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True) for _ in range(3)]


def say(frm, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    allout = settle(t0, quiet=1.5, cap=15)
    mine = [x for x in allout if x["body"].get("to", "").lstrip("+") == frm]
    return code, texts(mine), mine, allout


def buttons(out):
    ids = []
    for x in out:
        for grp in re.findall(r"\[(?:BTN|LIST) ([^\]]+)\]", x):
            ids += [p.split("=")[0] for p in re.split(r" \| |,", grp)]
    return ids


def orders_of(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + MID + '")'
              '->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->get()->map(fn($o)=>$o->only("id","number","status","delivery_lat","delivery_address","promised_at")));')


ZONES = tj('echo json_encode(App\\Models\\DeliveryZone::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("is_active",true)->pluck("id")->all());')
try:
    suite("W9 setup: a staff phone and a driver for the shop, zones switched off for the run")
    tj('$m=App\\Models\\Merchant::find("' + MID + '"); '
       'foreach([["+' + STAFF + '","staff","QA Pin Staff"],["+' + DRIVER + '","driver","QA Pin Driver"]] as [$p,$role,$n]) '
       '{ App\\Models\\Driver::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$m->id,"phone"=>$p],'
       '["name"=>$n,"role"=>$role,"whatsapp_number"=>$p,"password"=>"secret123","status"=>"off_duty","availability"=>"available","is_active"=>true]); } '
       'App\\Models\\DeliveryZone::withoutGlobalScopes()->where("merchant_id",$m->id)->update(["is_active"=>false]); '
       'echo json_encode(["ok"=>true]);')
    # Other drivers left on duty by earlier lab runs would be handed these orders: off duty for the run, restored after.
    OTHERS = tj('$ids=App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("role","driver")->where("phone","!=","+' + DRIVER + '")->where("status","on_duty")->pluck("id")->all(); '
                'App\\Models\\Driver::withoutGlobalScopes()->whereIn("id",$ids)->update(["status"=>"off_duty"]); echo json_encode($ids);')
    say(STAFF, text="hi")
    say(DRIVER, text="start")
    # Starting a shift hands the driver an older waiting order from earlier lab runs: free them for this order.
    tj('App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("phone","+' + DRIVER + '")->update(["active_order_id"=>null,"availability"=>"available"]); echo json_encode(1);')
    pid_item = tj('echo json_encode(App\\Models\\Product::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("is_active",true)->orderBy("name")->first()->id);')

    for lang, C in CUSTOMERS.items():
        suite(f"W9 {lang}: a typed address is not taken, the pin is")
        say(C, text=HI[lang], name=f"QA Pin {lang}")
        code, out, raw, _ = say(C, list_id=f"mn:item:{pid_item}")
        for _ in range(3):
            if "mn:qty:1" in buttons(out):
                break
            pick = next((i for i in buttons(out) if i.startswith(("mn:var:", "mn:mod:"))), None) or "mn:done"
            code, out, raw, _ = say(C, list_id=pick)
        code, out, raw, _ = say(C, button="mn:qty:1")
        code, out, raw, _ = say(C, button="co:start:")
        for _ in range(3):
            if raw and raw[-1]["body"].get("interactive", {}).get("type") == "location_request_message":
                break
            ids = buttons(out)
            pick = next((i for i in ids if i.startswith("co:ful:delivery")), None) or (ids[0] if ids else None)
            code, out, raw, _ = say(C, button=pick) if pick else (0, out, raw, None)
        asked = raw[-1]["body"].get("interactive", {}).get("type") if raw else None
        check(f"{lang}: delivery asks for the location with the button", asked == "location_request_message", out)
        print(f"   [{lang}] asks:", out[-1] if out else None)

        code, out, raw, _ = say(C, text="Jounieh, Main Street, Building 4, 2nd floor")
        again = raw[-1]["body"].get("interactive", {}).get("type") if raw else None
        check(f"{lang}: a typed address gets the location button again, no order", again == "location_request_message" and not orders_of(C), out)
        print(f"   [{lang}] typed address ->", out[-1] if out else None)

        code, out, raw, _ = say(C, location=(33.9822, 35.6401))
        check(f"{lang}: the pin is accepted and the note for the driver is asked", "co:skip:" in buttons(out), out)
        print(f"   [{lang}] after pin ->", out[-1] if out else None)
        code, out, raw, _ = say(C, text="Building 4, 2nd floor")
        for _ in range(4):
            ids = buttons(out)
            if "co:ok:" in ids:
                break
            pick = next((i for i in ids if i.startswith(("co:cur:USD", "co:pay:cash", "co:when:asap", "co:gift:no"))), None) or (ids[0] if ids else None)
            code, out, raw, _ = say(C, button=pick) if pick else (0, out, raw, None)
        receipt = " ".join(out)
        print(f"   [{lang}] receipt ->", receipt[-260:])
        check(f"{lang}: the receipt reaches Confirm", "co:ok:" in buttons(out), out)
        if lang == "en":
            check("en: the receipt says the preparation and that the arrival time comes later",
                  "Preparation about" in receipt and "arrival time when it is on its way" in receipt, receipt)
            check("en: the receipt does not promise an arrival time", "At your door" not in receipt, receipt)

        code, out, raw, allout = say(C, button="co:ok:")
        o = orders_of(C)
        check(f"{lang}: one order, with the customer's pin and the note", len(o) == 1 and o[0]["delivery_lat"] and "Building 4" in (o[0]["delivery_address"] or ""), o)
        print(f"   [{lang}] confirmed ->", out[-1] if out else None)
        suite(f"W9 {lang}: the shop accepts, the driver picks up")
        tj('App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("phone","+' + DRIVER + '")->update(["active_order_id"=>null,"availability"=>"available"]); echo json_encode(1);')
        o = o[0]
        code, out, raw, allout = say(STAFF, button=f"stf:accept:{o['id']}")
        mine = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == C])
        print(f"   [{lang}] customer after accept ->", mine)
        if lang == "en":
            check("en: accepted — preparing now, ready around", any("accepted your order" in x and "preparing now, ready around" in x for x in mine), mine)
        else:
            check(f"{lang}: the customer is told the order is accepted, with a time", any(f"{o['number']}" in x and re.search(r"\d{1,2}[:.]\d{2}", x) for x in mine), mine)
        drv = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->find("' + o["id"] + '")->driver_id);')
        check(f"{lang}: the order has a driver", bool(drv), drv)
        code, out, raw, allout = say(DRIVER, button=f"drv:picked_up:{o['id']}")
        mine = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == C])
        print(f"   [{lang}] customer after pick-up ->", mine)
        if lang == "en":
            check("en: on its way with the driver — arriving in about N min (around time)",
                  any("on its way with QA Pin Driver — arriving in about" in x and "(around" in x for x in mine), mine)
        else:
            check(f"{lang}: the customer gets the arrival in minutes and the clock time", any(re.search(r"\d+", x) and "(" in x for x in mine), mine)
        code, out, raw, allout = say(DRIVER, button=f"drv:delivered:{o['id']}")
        check(f"{lang}: delivered", orders_of(C)[0]["status"] == "delivered", orders_of(C))
finally:
    subprocess.run(["pkill", "-9", "-f", "queue:work"])
    if "OTHERS" in globals():
        tj('App\\Models\\Driver::withoutGlobalScopes()->whereIn("id",' + json.dumps(OTHERS) + ')->update(["status"=>"on_duty"]); echo json_encode(1);')
        tj('App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("phone","+' + DRIVER + '")->update(["status"=>"off_duty","active_order_id"=>null]); echo json_encode(1);')
    tj('App\\Models\\DeliveryZone::withoutGlobalScopes()->whereIn("id",' + json.dumps(ZONES) + ')->update(["is_active"=>true]); echo json_encode(1);')
