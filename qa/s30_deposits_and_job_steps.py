"""Deposits on bookings and job steps on repair orders (real AI; queue worker started with lab-ai.sh).
Deposits: a car wash with staff (English) and a tutor whose owner works alone (Arabic): the time is held, the customer
pays by Whish and taps "I paid", the shop confirms or refuses from WhatsApp, an unpaid hold is released by the queue at
the end of its window. Job steps: a garage (French) takes a repair through a quote, then the staff move it through its
steps and the customer is told at each. Lab only."""
import datetime, json
from lib import *

s = state(); OWN = s["owner"]
RUN = str(int(time.time()))[-5:]
n = [0]


def fresh():
    n[0] += 1
    return f"9617850{RUN}{n[0]:02d}"


def send(pid, frm, **kw):
    code, mid, t0 = wa_msg(pid, frm, **kw)
    return t0


def got(phone, t0, wait=4):
    time.sleep(wait)
    return [x["body"] for x in outbox(since=t0) if x["body"].get("to", "").lstrip("+") == phone.lstrip("+")]


def buttons(body):
    return [b["reply"]["id"] for b in body.get("interactive", {}).get("action", {}).get("buttons", [])]


def rows(body):
    return [r["id"] for sec in body.get("interactive", {}).get("action", {}).get("sections", []) for r in sec.get("rows", [])]


def txt(bodies):
    return texts([{"body": b} for b in bodies])


def setup(i, name, svc, lang, staffed, profile, price=10, mins=30):
    phone = f"+9617{RUN}{i:02d}6"
    code, b = api("POST", "/admin/merchants", OWN, {"shopName": f"QA {name} D {RUN}", "whatsappNumber": phone, "password": "123456", "businessType": "Other", "subscriptionStatus": "trial", "country": "LB"})
    m = b["data"]; pid = f"PE{RUN}{i:02d}"
    api("PATCH", f"/admin/merchants/{m['id']}/bot", OWN, {"phoneNumberId": pid, "accessToken": "lab-shop-token"})
    api("POST", f"/admin/merchants/{m['id']}/bot/restart", OWN)
    tinker('App\\Models\\Merchant::find("' + m["id"] + '")->update(["locale"=>"' + lang + '"]);')
    code, lb = login(f"{m['slug']}@merchant.neotalab", "123456"); tok = lb["token"]
    api("PATCH", "/settings/profile", tok, profile | {"whish_number": "70123456"})
    code, cat = api("POST", "/categories", tok, {"name": "Main"})
    body = {"name": svc, "price": price, "category_id": (cat.get("data") or cat)["id"]}
    if mins:
        body["duration_mins"] = mins
    api("POST", "/products", tok, body)
    who = phone.lstrip("+")
    if staffed:
        who = f"9617860{RUN}{i:02d}"
        api("POST", "/drivers", tok, {"name": "Desk", "phone": "+" + who, "whatsappNumber": "+" + who, "role": "staff"})
    send(pid, who, text="hi")
    return {"id": m["id"], "pid": pid, "token": tok, "who": who, "lang": lang}


def appt(shop_id, phone):
    q = 'App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id","' + shop_id + '")->whereHas("customer", fn($c)=>$c->withoutGlobalScopes()->where("phone","+' + phone + '"))'
    return tj('echo json_encode(' + q + '->latest()->get()->map(fn($a)=>[$a->id,$a->status,$a->deposit_amount,$a->deposit_code,$a->deposit_paid_at?1:0])->all());')


BOOK = {"en": "Please book {svc} tomorrow at {t}", "ar": "احجز لي {svc} غداً الساعة {t}", "fr": "Réservez-moi {svc} demain à {t}"}
WORDS = {"en": ("deposit of", "Deposit received", "could not find"), "ar": ("عربوناً", "تم استلام العربون", "لم يجد"), "fr": ("acompte de", "Acompte reçu", "n'a pas trouvé")}

for i, (name, svc, lang, staffed) in enumerate([("Car wash", "Full wash", "en", True), ("Tutor", "Maths lesson", "ar", False)]):
    suite(f"Deposits: {name} ({lang}, {'with staff' if staffed else 'owner alone'})")
    sh = setup(i, name, svc, lang, staffed, {"business_mode": "appointments", "appointment_capacity": 1, "slot_step_mins": 30, "deposit_percent": 50, "wishTimeoutMins": 5}, price=20)
    pid, who = sh["pid"], sh["who"]
    dep_word, ok_word, no_word = WORDS[lang]

    # 1. the time is held and the customer is told how to pay
    c1 = fresh(); t0 = send(pid, c1, text=BOOK[lang].format(svc=svc, t="10:00" if lang != "fr" else "10h00"))
    time.sleep(10)
    a = appt(sh["id"], c1)
    check(f"[{name}] the booking is held, not yet booked", len(a) == 1 and a[0][1] == "pending_deposit" and float(a[0][2]) == 10.0, a)
    out = got(c1, t0, 0)
    inst = [b for b in out if "wh:dpaid" in json.dumps(b)]
    check(f"[{name}] the customer gets the Whish instructions with an \"I paid\" button", len(inst) == 1 and a and inst[0]["interactive"]["body"]["text"].count(a[0][3]) == 1 and "70123456" in inst[0]["interactive"]["body"]["text"], txt(out)[:1])
    no_ticket = [b for b in got(who, t0, 0) if "stf:appt_change" in json.dumps(b)]
    check(f"[{name}] the team hears nothing before the deposit is confirmed", not no_ticket, no_ticket)

    # 2. a second customer cannot take the held time
    c2 = fresh(); send(pid, c2, text=BOOK[lang].format(svc=svc, t="10:00" if lang != "fr" else "10h00")); time.sleep(10)
    check(f"[{name}] nobody else can book the held time", not [x for x in appt(sh["id"], c2) if x[1] in ("pending_deposit", "booked", "deposit_check")], appt(sh["id"], c2))

    # 3. "I paid" -> the shop (or the owner alone) gets the check, and confirms
    t1 = time.time(); send(pid, c1, button=f"wh:dpaid:{a[0][0]}")
    time.sleep(5)
    check(f"[{name}] after \"I paid\" the booking waits for the shop's check", appt(sh["id"], c1)[0][1] == "deposit_check", appt(sh["id"], c1))
    check_msgs = [b for b in got(who, t1, 0) if "stf:dp_ok" in json.dumps(b)]
    check(f"[{name}] the {'staff' if staffed else 'owner'} get the check with Received / Not received and the note to look for",
          len(check_msgs) == 1 and a[0][3] in check_msgs[0]["interactive"]["body"]["text"] and buttons(check_msgs[0]) == [f"stf:dp_ok:{a[0][0]}", f"stf:dp_no:{a[0][0]}"], txt(got(who, t1, 0))[:1])
    t2 = time.time(); send(pid, who, button=f"stf:dp_ok:{a[0][0]}")
    time.sleep(6)
    paid = appt(sh["id"], c1)[0]
    check(f"[{name}] Received confirms the booking and marks the deposit paid", paid[1] == "booked" and paid[4] == 1, paid)
    check(f"[{name}] the customer is told it is confirmed, in their language", any(ok_word in x for x in txt(got(c1, t2, 0))), txt(got(c1, t2, 0)))
    ticket = [b for b in got(who, t2, 0) if "stf:appt_change" in json.dumps(b)]
    check(f"[{name}] only now does the team get the booking ticket", len(ticket) == 1, txt(got(who, t2, 0)))
    queued = tj('echo json_encode(DB::table("jobs")->where("payload","like","%' + a[0][0] + '%")->where("payload","like","%SendAppointmentReminder%")->count());')
    check(f"[{name}] and the reminder is queued", queued >= 1, queued)

    # 4. Not received -> cancelled, the time is free
    c3 = fresh(); send(pid, c3, text=BOOK[lang].format(svc=svc, t="11:00" if lang != "fr" else "11h00")); time.sleep(10)
    b3 = appt(sh["id"], c3)
    if b3:
        send(pid, c3, button=f"wh:dpaid:{b3[0][0]}"); time.sleep(5)
        t3 = time.time(); send(pid, who, button=f"stf:dp_no:{b3[0][0]}"); time.sleep(6)
        check(f"[{name}] Not received cancels the booking", appt(sh["id"], c3)[0][1] == "cancelled", appt(sh["id"], c3))
        check(f"[{name}] and tells the customer", any(no_word in x for x in txt(got(c3, t3, 0))), txt(got(c3, t3, 0)))
    else:
        check(f"[{name}] a booking exists for the refusal check", False, b3)

    # 5. an unpaid hold is released by the queue at the end of its window (5 minutes)
    c4 = fresh(); t4 = send(pid, c4, text=BOOK[lang].format(svc=svc, t="12:00" if lang != "fr" else "12h00")); time.sleep(10)
    b4 = appt(sh["id"], c4)
    if b4:
        print("  waiting for the payment window (5 minutes) to end…")
        end = time.time() + 400; state_now = b4[0][1]
        while time.time() < end and state_now == "pending_deposit":
            time.sleep(10); state_now = appt(sh["id"], c4)[0][1]
        check(f"[{name}] the queue releases an unpaid hold when the window ends", state_now == "cancelled", state_now)
    else:
        check(f"[{name}] a booking exists for the expiry check", False, b4)

# ── job steps ───────────────────────────────────────────────────────────────
suite("Job steps: garage (fr, with staff)")
sh = setup(5, "Garage", "Vidange", "fr", True, {"business_mode": "orders", "quotes": True, "job_stages": ["Voiture reçue", "Travail en cours", "Prête à récupérer"], "fulfillment": "pickup", "mode": "auto"}, price=40, mins=None)
pid, who = sh["pid"], sh["who"]
c = fresh(); t0 = send(pid, c, text="Bonjour, je voudrais un devis pour une vidange de ma Corolla")
time.sleep(15)
q = tj('$q=App\\Models\\Quote::withoutGlobalScopes()->where("merchant_id","' + sh["id"] + '")->latest()->first(); echo json_encode($q?$q->only(["id","number","status"]):null);')
check("the customer's request becomes a quote", q is not None and q["status"] == "requested", q)
if q:
    send(pid, who, text=f"quote {q['number']} 85 Vidange complète"); time.sleep(6)
    send(pid, c, button=f"qt:yes:{q['id']}"); time.sleep(8)
    o = tj('$o=App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + sh["id"] + '")->latest()->first(); echo json_encode($o?$o->only(["id","number","status","job_stage"]):null);')
    check("accepting the quote makes an order", o is not None, o)
    if o:
        cards = [b for b in got(who, t0, 0) if "stf:job_next" in json.dumps(b)]
        if not cards and o["status"] == "placed":
            send(pid, who, button=f"stf:accept:{o['id']}"); time.sleep(6)
            cards = [b for b in got(who, t0, 0) if "stf:job_next" in json.dumps(b)]
        check("the staff get the job card with Next stage / All stages / Ready", len(cards) >= 1 and buttons(cards[-1])[:2] == [f"stf:job_next:{o['id']}", f"stf:job_pick:{o['id']}"], txt(got(who, t0, 0))[-1:])
        t1 = time.time(); send(pid, who, button=f"stf:job_next:{o['id']}"); time.sleep(5)
        st = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->find("' + o["id"] + '")->job_stage);')
        check("Next stage starts the job at the first step", st == 0, st)
        check("the customer is told, in French", any("Voiture reçue" in x for x in txt(got(c, t1, 0))), txt(got(c, t1, 0)))
        t2 = time.time(); send(pid, who, button=f"stf:job_pick:{o['id']}"); time.sleep(4)
        lst = [b for b in got(who, t2, 0) if b.get("interactive", {}).get("type") == "list"]
        check("All stages lists the steps", len(lst) == 1 and rows(lst[0]) == [f"stf:job_set:{o['id']}:{k}" for k in range(3)], txt(got(who, t2, 0)))
        t3 = time.time(); send(pid, who, list_id=f"stf:job_set:{o['id']}:2"); time.sleep(5)
        st = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->find("' + o["id"] + '")->job_stage);')
        check("picking a step moves the job there", st == 2, st)
        check("the customer is told the last step", any("Prête à récupérer" in x for x in txt(got(c, t3, 0))), txt(got(c, t3, 0)))
        t4 = time.time(); code, mid, _ = wa_msg(pid, c, button=f"trk:{o['id']}"); time.sleep(5)
        check("\"Where is my order?\" shows the step", any("Étape du travail : Prête à récupérer" in x for x in txt(got(c, t4, 0))), txt(got(c, t4, 0)))
        t5 = time.time(); send(pid, who, button=f"stf:job_next:{o['id']}"); time.sleep(4)
        check("Next stage at the last step says there is none", any("dernière étape" in x for x in txt(got(who, t5, 0))), txt(got(who, t5, 0)))
