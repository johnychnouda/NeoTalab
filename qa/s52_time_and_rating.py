"""Scale lab: the shop's own time by default, "Another time" (one list), a time written in plain words, and the three
one-tap rating questions after delivery, in English, Arabic and French, through the real webhook and the queue.
Order A: asap, delivered, rated Great / Okay / Bad (a Bad answer alerts the staff). Order B: the customer writes the
time they want. WhatsApp is the lab stand-in. Run: ./scale_env.sh python3 -u s52_time_and_rating.py"""
import os, re, subprocess
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"] or ENV["DB_DATABASE"].startswith(LAB), "refusing: not a lab"
s = state(); r = (s.get("scale") or {}).get("sc000") or {**s["shops"]["rest"], "pid": s["shops"]["rest"]["phone_id"]}; PID = r["pid"]; MID = r["id"]  # a scale-lab restaurant with a menu, or the small lab's restaurant
open_all_day()
RUN = str(int(time.time()))[-5:]
STAFF, DRIVER = "96171993" + RUN[-3:], "96171994" + RUN[-3:]
CUSTOMERS = {"en": "9617620" + RUN, "ar": "9617621" + RUN, "fr": "9617622" + RUN}
HI = {"en": "hi", "ar": "مرحبا", "fr": "bonjour"}
WRITTEN = {"en": "tomorrow at 7 pm", "ar": "بكرا الساعة 7 مساء", "fr": "demain à 19h"}
FIRST_Q = {"en": "1 of 3", "ar": "1 من 3", "fr": "1 sur 3"}
BAD_WORD = {"en": "rated Bad", "ar": "سيء", "fr": "mauvaise note"}
SHOP_LANG = tj('echo json_encode(App\\Models\\Merchant::find("' + MID + '")->locale ?: "en");')
workers = [subprocess.Popen([LABSH, "php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--timeout=75"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True) for _ in range(3)]


def say(frm, quiet=1.5, cap=15, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    allout = settle(t0, quiet=quiet, cap=cap)
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
              '->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->orderBy("created_at")->get()->map(fn($o)=>$o->only("id","number","status","deliver_at","promised_at","rating","rating_service","rating_delivery")));')


ZONES = tj('echo json_encode(App\\Models\\DeliveryZone::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("is_active",true)->pluck("id")->all());')


def dbg(step, out):
    if os.environ.get("S52_DEBUG"):
        print(f"      · {step}: {buttons(out)[:6]} | {' '.join(out)[:110]!r}")


def to_summary(C, lang, name):
    """From hello to the order summary (pin, note, cash, dollars)."""
    _, out0, _, _ = say(C, text=HI[lang], name=name)
    dbg("hello", out0)
    put_in_cart(MID, C, pid_item, 1)  # the item the customer chose
    code, out, raw, _ = say(C, text="done")  # the stand-in assistant's "that's all": the checkout starts
    dbg("start checkout", out)
    asked_pin = lambda: bool(raw) and raw[-1]["body"].get("interactive", {}).get("type") == "location_request_message"
    for _ in range(3):  # Delivery, or a returning customer's saved address (one tap): never tap Confirm here
        ids = buttons(out)
        if "co:ok:" in ids or asked_pin():
            break
        pick = next((i for i in ids if i.startswith("co:ful:delivery")), None) or next((i for i in ids if i.startswith("co:addr:") and i != "co:addr:new"), None)
        if not pick:
            break
        code, out, raw, _ = say(C, button=pick)
        dbg("pick", out)
    if asked_pin():
        code, out, raw, _ = say(C, location=(33.9822, 35.6401))
        code, out, raw, _ = say(C, text="Building 4, 2nd floor")
        dbg("pin and note", out)
    for _ in range(4):  # cash and dollars, up to the summary
        ids = buttons(out)
        if "co:ok:" in ids:
            break
        pick = next((i for i in ids if i.startswith(("co:cur:USD", "co:pay:cash"))), None)
        if not pick:
            break
        code, out, raw, _ = say(C, button=pick)
    return out


try:
    suite("W10 setup: a staff phone and a driver, zones switched off for the run")
    tj('$m=App\\Models\\Merchant::find("' + MID + '"); '
       'foreach([["+' + STAFF + '","staff","QA Time Staff"],["+' + DRIVER + '","driver","QA Time Driver"]] as [$p,$role,$n]) '
       '{ App\\Models\\Driver::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$m->id,"phone"=>$p],'
       '["name"=>$n,"role"=>$role,"whatsapp_number"=>$p,"password"=>"secret123","status"=>"off_duty","availability"=>"available","is_active"=>true]); } '
       'App\\Models\\DeliveryZone::withoutGlobalScopes()->where("merchant_id",$m->id)->update(["is_active"=>false]); echo json_encode(["ok"=>true]);')
    # Other drivers left on duty by earlier lab runs would be handed these orders: off duty for the run, restored after.
    OTHERS = tj('$ids=App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("role","driver")->where("phone","!=","+' + DRIVER + '")->where("status","on_duty")->pluck("id")->all(); '
                'App\\Models\\Driver::withoutGlobalScopes()->whereIn("id",$ids)->update(["status"=>"off_duty"]); echo json_encode($ids);')
    say(STAFF, text="hi")
    say(DRIVER, text="start")
    pid_item = tj('echo json_encode(App\\Models\\Product::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("is_active",true)->where("is_available",true)->where("track_inventory",false)->doesntHave("variants")->doesntHave("modifierGroups")->orderBy("name")->first()->id);')

    for lang, C in [(l, c) for l, c in CUSTOMERS.items() if l in os.environ.get("S52_LANGS", "en,ar,fr").split(",")]:
        suite(f"W10 {lang}: the shop's own time, Another time, a written time")
        out = to_summary(C, lang, f"QA Time {lang}")
        summary = " ".join(out)
        print(f"   [{lang}] summary ->", summary[-300:])
        check(f"{lang}: the summary has Confirm / Another time / Cancel and no time question was asked", buttons(out)[-3:] == ["co:ok:", "co:time:", "co:cancel:"], buttons(out))
        check(f"{lang}: it shows the shop's own time", "⏱" in summary, summary[-200:])

        code, out, raw, _ = say(C, button="co:time:")
        ids = buttons(out)
        print(f"   [{lang}] another time ->", out[-1][:160] if out else None)
        check(f"{lang}: Another time is one list of times with Another day last", len([i for i in ids if i.startswith("co:win:")]) >= 3 and ids[-1] == "co:when:pick", ids)
        first = next(i for i in ids if i.startswith("co:win:"))
        code, out, raw, _ = say(C, list_id=first)
        print(f"   [{lang}] picked ->", " ".join(out)[-150:])
        check(f"{lang}: the pick shows on the summary with Soonest possible", "🗓" in " ".join(out) and buttons(out)[-3:] == ["co:ok:", "co:when:asap", "co:cancel:"], buttons(out))

        code, out, raw, _ = say(C, button="co:when:asap")
        check(f"{lang}: Soonest possible goes back to the shop's own time", "⏱" in " ".join(out) and "🗓" not in " ".join(out), out)

        code, out, raw, _ = say(C, button="co:ok:")
        suite(f"W10 {lang}: delivered, then three one-tap questions")
        o = orders_of(C)[-1]
        # Starting a shift hands the driver an older waiting order from earlier lab runs: free them for this order.
        tj('App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("phone","+' + DRIVER + '")->update(["active_order_id"=>null,"availability"=>"available"]); echo json_encode(1);')
        say(STAFF, button=f"stf:accept:{o['id']}")
        say(DRIVER, button=f"drv:picked_up:{o['id']}")
        code, out, raw, allout = say(DRIVER, quiet=4, cap=40, button=f"drv:delivered:{o['id']}")
        mine = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == C])
        if os.environ.get("S52_DEBUG"):
            print("      · order after delivered tap:", tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->find("' + o["id"] + '")->only("status","driver_id","rating_requested_at"));'))
            print("      · outbound to customer in the last 2 min:", tj('echo json_encode(App\\Models\\WhatsAppMessage::withoutGlobalScopes()->where("direction","outbound")->where("to_number","+' + C + '")->where("created_at",">",now()->subMinutes(3))->orderBy("created_at")->get()->map(fn($m)=>mb_substr(json_encode($m->payload, JSON_UNESCAPED_UNICODE),0,140))->all());'))
        q1 = next((x for x in mine if FIRST_Q[lang] in x), "")
        print(f"   [{lang}] after delivery ->", q1[-200:])
        check(f"{lang}: the delivered message carries question 1 of 3 with Great / Okay / Bad", FIRST_Q[lang] in q1 and f"rt:{o['id']}:order:5" in q1, mine)
        say(C, button=f"rt:{o['id']}:order:5")
        code, out, raw, allout = say(C, button=f"rt:{o['id']}:service:3")
        check(f"{lang}: question 3 names the delivery person", "QA Time Driver" in " ".join(out), out)
        code, out, raw, allout = say(C, button=f"rt:{o['id']}:delivery:1")
        staff_msgs = texts([x for x in allout if x["body"].get("to", "").lstrip("+") == STAFF])
        print(f"   [{lang}] staff alert ->", staff_msgs[-1][:160] if staff_msgs else None)
        check(f"{lang}: a Bad answer alerts the staff, in the shop's language ({SHOP_LANG}), and says what", any(BAD_WORD[SHOP_LANG] in m and "QA Time Driver" in m for m in staff_msgs), staff_msgs)
        done = orders_of(C)[-1]
        check(f"{lang}: the three answers are saved (5, 3, 1)", [done["rating"], done["rating_service"], done["rating_delivery"]] == [5, 3, 1], done)

        suite(f"W10 {lang}: the customer writes the time")
        out = to_summary(C, lang, f"QA Time {lang}")
        print(f"   [{lang}] second summary buttons ->", buttons(out), "|", " ".join(out)[-120:].replace("\n", " "))
        code, out, raw, _ = say(C, text=WRITTEN[lang])
        written = " ".join(out)
        print(f"   [{lang}] wrote '{WRITTEN[lang]}' ->", written[-170:])
        check(f"{lang}: the written time becomes the order's slot (19:00–20:00)", "🗓" in written and "19:00–20:00" in written, written[-200:])
        code, out, raw, _ = say(C, button="co:ok:")
        b = orders_of(C)[-1]
        check(f"{lang}: the order is saved for that time", bool(b["deliver_at"]), b)
finally:
    if 'OTHERS' in globals():
        tj('App\\Models\\Driver::withoutGlobalScopes()->whereIn("id",' + json.dumps(OTHERS) + ')->update(["status"=>"on_duty"]); echo json_encode(1);')
    tj('App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + MID + '")->where("phone","+' + DRIVER + '")->update(["status"=>"off_duty","active_order_id"=>null]); echo json_encode(1);')
    for w in workers:
        w.kill()
    subprocess.run(["pkill", "-9", "-f", "queue:work"])
    tj('App\\Models\\DeliveryZone::withoutGlobalScopes()->whereIn("id",' + json.dumps(ZONES) + ')->update(["is_active"=>true]); echo json_encode(1);')
