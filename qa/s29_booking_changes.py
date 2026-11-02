"""The shop changes a booking from WhatsApp (real AI; queue worker started with lab-ai.sh), and the parts the earlier
booking scripts could not reach: messages after WhatsApp's 24 hours (templates marked approved in the lab) and a
reminder that goes out at its real time through the queue. Three shops, three languages; the tutor's owner works alone.
Lab only."""
import datetime, json
from lib import *

s = state(); OWN = s["owner"]
RUN = str(int(time.time()))[-5:]
SHOPS = [  # name, service, language of the shop and its customers, has staff
    ("Car wash", "Full wash", "en", True),
    ("Tutor", "Maths lesson", "ar", False),
    ("Garage", "Oil change", "fr", True),
]
BOOK = {"en": "Please book {svc} tomorrow at 10:00", "ar": "احجز لي {svc} غداً الساعة 10:00", "fr": "Réservez-moi {svc} demain à 10h00"}
MOVED = {"en": "moved your", "ar": "نقل", "fr": "a déplacé"}
CANCELLED = {"en": "cancelled your", "ar": "ألغى", "fr": "a annulé"}
n = [0]


def fresh():
    n[0] += 1
    return f"9617830{RUN}{n[0]:02d}"


def send(pid, frm, **kw):
    code, mid, t0 = wa_msg(pid, frm, **kw)
    return t0


def settle_to(phone, t0, wait=4):
    time.sleep(wait)
    return [x["body"] for x in outbox(since=t0) if x["body"].get("to", "").lstrip("+") == phone.lstrip("+")]


def buttons(body):
    i = body.get("interactive", {})
    return [b["reply"]["id"] for b in i.get("action", {}).get("buttons", [])]


def rows(body):
    return [r["id"] for sec in body.get("interactive", {}).get("action", {}).get("sections", []) for r in sec.get("rows", [])]


def appt(shop_id, phone=None):
    q = 'App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id","' + shop_id + '")'
    if phone:
        q += '->whereHas("customer", fn($c)=>$c->withoutGlobalScopes()->where("phone","+' + phone + '"))'
    return tj('echo json_encode(' + q + '->latest()->get()->map(fn($a)=>[$a->id,$a->status,$a->starts_at->setTimezone("Asia/Beirut")->format("Y-m-d H:i"),$a->cancelled_by])->all());')


def approve(shop_id, *names):
    for nm in names:
        tinker('$m=App\\Models\\Merchant::find("' + shop_id + '"); $s=$m->settings; $s["bot"]["templates"]["' + nm + '"]=["status"=>"APPROVED"]; $m->settings=$s; $m->save();')


def age(shop_id, phone, days=2):
    tinker('DB::table("whatsapp_messages")->where("merchant_id","' + shop_id + '")->where("from_number","+' + phone.lstrip("+") + '")->update(["created_at"=>now()->subDays(' + str(days) + ')]);')


def setup(i, name, svc, lang, staffed, extra=None):
    phone = f"+9617{RUN}{i:02d}7"
    code, b = api("POST", "/admin/merchants", OWN, {"shopName": f"QA {name} C {RUN}", "whatsappNumber": phone, "password": "123456", "businessType": "Other", "subscriptionStatus": "trial", "country": "LB"})
    m = b["data"]; pid = f"PD{RUN}{i:02d}"
    api("PATCH", f"/admin/merchants/{m['id']}/bot", OWN, {"phoneNumberId": pid, "accessToken": "lab-shop-token"})
    api("POST", f"/admin/merchants/{m['id']}/bot/restart", OWN)
    tinker('App\\Models\\Merchant::find("' + m["id"] + '")->update(["locale"=>"' + lang + '"]);')
    code, lb = login(f"{m['slug']}@merchant.neotalab", "123456"); tok = lb["token"]
    api("PATCH", "/settings/profile", tok, {"business_mode": "appointments", "appointment_capacity": 3, "slot_step_mins": 30, **(extra or {})})
    code, cat = api("POST", "/categories", tok, {"name": "Main"})
    api("POST", "/products", tok, {"name": svc, "price": 10, "duration_mins": 30, "category_id": (cat.get("data") or cat)["id"]})
    who = phone.lstrip("+")  # the owner's number
    if staffed:
        who = f"9617840{RUN}{i:02d}"
        api("POST", "/drivers", tok, {"name": "Desk", "phone": "+" + who, "whatsappNumber": "+" + who, "role": "staff"})
    send(pid, who, text="hi")  # inside WhatsApp's 24 hours
    return {"id": m["id"], "pid": pid, "token": tok, "who": who, "lang": lang}


for i, (name, svc, lang, staffed) in enumerate(SHOPS):
    suite(f"Booking changes: {name} ({lang}, {'with staff' if staffed else 'owner alone'})")
    sh = setup(i, name, svc, lang, staffed)
    who, pid = sh["who"], sh["pid"]

    # 1. a booking, and its ticket with Done / No-show / Change (to the staff, or to the owner)
    c1 = fresh(); t0 = send(pid, c1, text=BOOK[lang].format(svc=svc))
    time.sleep(8)
    a = appt(sh["id"], c1)
    check(f"[{name}] customer is booked", len(a) == 1 and a[0][1] == "booked", a)
    ticket = [bd for bd in settle_to(who, t0, 1) if "stf:appt_change" in json.dumps(bd)]
    check(f"[{name}] the {'staff' if staffed else 'owner'} get the ticket with three buttons", len(ticket) == 1 and len(buttons(ticket[0])) == 3, ticket)
    aid = a[0][0]

    # 2. Change -> Move it / Cancel it / Keep it
    t1 = time.time(); send(pid, who, button=f"stf:appt_change:{aid}")
    msgs = settle_to(who, t1, 3)
    check(f"[{name}] Change offers Move it, Cancel it and Keep it", msgs and buttons(msgs[-1]) == [f"stf:appt_move:{aid}", f"stf:appt_cancel:{aid}", f"stf:appt_keep:{aid}"], msgs)

    # 3. Move it -> a list of free times; picking one moves the booking; the customer is told in their language
    t2 = time.time(); send(pid, who, button=f"stf:appt_move:{aid}")
    msgs = settle_to(who, t2, 3)
    options = rows(msgs[-1]) if msgs else []
    check(f"[{name}] Move it lists free times (up to 10)", 1 <= len(options) <= 10, options[:3])
    pick = next((o for o in options if o.endswith("1530")), options[0] if options else None)
    t3 = time.time(); send(pid, who, list_id=pick)
    msgs_staff = settle_to(who, t3, 4)
    after = appt(sh["id"], c1)
    new_slot = pick[-12:-8] + "-" + pick[-8:-6] + "-" + pick[-6:-4] + " " + pick[-4:-2] + ":" + pick[-2:] if pick else ""
    check(f"[{name}] the booking moved to the chosen time", after and after[0][2] == new_slot and after[0][1] == "booked", (after, new_slot))
    told = [t for t in texts([{"body": bd} for bd in settle_to(c1, t3, 0)]) if MOVED[lang] in t]
    check(f"[{name}] the customer is told, in their language", len(told) == 1, texts([{"body": bd} for bd in settle_to(c1, t3, 0)]))
    queued = tj('echo json_encode(DB::table("jobs")->where("payload","like","%' + aid + '%")->where("payload","like","%SendAppointmentReminder%")->count());')
    check(f"[{name}] a reminder is queued for the new time", queued >= 1, queued)

    # 4. Cancel it -> the customer is told; the owner/staff get the confirmation; the time is free again
    c2 = fresh(); send(pid, c2, text=BOOK[lang].format(svc=svc)); time.sleep(8)
    b2 = appt(sh["id"], c2)
    t4 = time.time(); send(pid, who, button=f"stf:appt_cancel:{b2[0][0]}")
    time.sleep(4)
    end = appt(sh["id"], c2)
    check(f"[{name}] Cancel it cancels the booking as the shop's", end and end[0][1] == "cancelled" and end[0][3] == "shop", end)
    told = [t for t in texts([{"body": bd} for bd in settle_to(c2, t4, 0)]) if CANCELLED[lang] in t]
    check(f"[{name}] the customer is told it was cancelled", len(told) == 1, texts([{"body": bd} for bd in settle_to(c2, t4, 0)]))

    # 5. "today" / "tomorrow" list the bookings
    t5 = time.time(); send(pid, who, text={"en": "tomorrow", "ar": "غداً", "fr": "demain"}[lang])
    msgs = texts([{"body": bd} for bd in settle_to(who, t5, 4)])
    check(f"[{name}] asking for tomorrow lists the bookings", any(svc in m for m in msgs) or any(("10:00" in m or "15:30" in m) for m in msgs), msgs)

    # 6. after WhatsApp's 24 hours: the customer's cancellation notice goes as the approved template
    c3 = fresh(); send(pid, c3, text=BOOK[lang].format(svc=svc)); time.sleep(8)
    b3 = appt(sh["id"], c3)
    if b3 and b3[0][1] == "booked":
        approve(sh["id"], f"neotalab_c_booking_cancelled_shop_{lang}", "neotalab_c_booking_cancelled_shop_en")
        age(sh["id"], c3, 2)
        t6 = time.time(); send(pid, who, button=f"stf:appt_cancel:{b3[0][0]}")
        msgs = settle_to(c3, t6, 5)
        tpl = [m for m in msgs if m.get("type") == "template"]
        check(f"[{name}] two days later the customer gets the approved template", len(tpl) == 1 and tpl[0]["template"]["name"].startswith("neotalab_c_booking_cancelled_shop"), msgs)
    else:
        check(f"[{name}] a booking exists for the template check", False, b3)

    # 7. after WhatsApp's 24 hours: the booking ticket reaches the staff / owner as the template with its three buttons
    approve(sh["id"], f"neotalab_new_booking_{lang}", "neotalab_new_booking")
    age(sh["id"], who, 2)
    c4 = fresh(); t7 = send(pid, c4, text=BOOK[lang].format(svc=svc)); time.sleep(10)
    tpl = [m for m in settle_to(who, t7, 1) if m.get("type") == "template"]
    check(f"[{name}] two days later the ticket goes as the template with three quick replies", len(tpl) == 1 and sum(1 for c in tpl[0]["template"]["components"] if c.get("type") == "button") == 3, tpl)

    # 8. the owner alone: a stranger's tap on a booking button changes nothing
    if not staffed:
        b4 = appt(sh["id"], c4)
        stranger = fresh()
        send(pid, stranger, button=f"stf:appt_cancel:{b4[0][0]}"); time.sleep(4)
        still = appt(sh["id"], c4)
        check(f"[{name}] someone else's tap on Cancel changes nothing", still and still[0][1] == "booked", still)

# the reminder at its real time, through the queue
suite("Reminder at its real time (queue)")
now = datetime.datetime.utcnow() + datetime.timedelta(hours=2)  # lab shops run on Beirut time
sh = setup(9, "Reminder", "Quick wash", "en", True)
slot = (now + datetime.timedelta(minutes=45)).replace(second=0, microsecond=0)
slot = slot.replace(minute=0 if slot.minute < 30 else 30)
if slot < now + datetime.timedelta(minutes=20):
    slot += datetime.timedelta(minutes=30)
mins_to_slot = int((slot - now).total_seconds() // 60)
remind = max(1, mins_to_slot - 3)  # the reminder is due about three minutes from now
api("PATCH", "/settings/profile", sh["token"], {"reminder_mins": remind})
c = fresh()
t0 = send(sh["pid"], c, text=f"Please book Quick wash today at {slot.strftime('%H:%M')}")
time.sleep(10)
a = appt(sh["id"], c)
check("a booking for a time soon is made", len(a) == 1 and a[0][1] == "booked", (a, slot.isoformat()))
if a:
    due = slot - datetime.timedelta(minutes=remind)
    wait_s = max(0, (due - (datetime.datetime.utcnow() + datetime.timedelta(hours=2))).total_seconds()) + 40
    print(f"  waiting about {int(wait_s)} seconds for the queue to send the reminder due at {due.strftime('%H:%M')}")
    end = time.time() + wait_s + 60
    got = []
    while time.time() < end and not got:
        time.sleep(5)
        got = [t for t in texts([{"body": bd} for bd in settle_to(c, t0, 0)]) if "Reminder" in t]
    check("the reminder arrives by itself at its time, in English", len(got) == 1, texts([{"body": bd} for bd in settle_to(c, t0, 0)]))
