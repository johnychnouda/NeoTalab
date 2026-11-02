"""Bookings, the parts s27 did not cover (real AI; the queue worker must be started with lab-ai.sh):
a customer who gives service, day and time in one message is booked at once; the staff get the booking
ticket and can tap Done / No-show; a customer's cancellation reaches the staff; the reminder is queued,
is sent in the customer's language, and is never sent for a cancelled booking. Three shops, three languages. Lab only."""
import datetime
from lib import *

s = state(); OWN = s["owner"]
RUN = str(int(time.time()))[-5:]
# Customers say "tomorrow": the shops are new every run, so tomorrow at 10:00 is always free.
DAY = ((datetime.datetime.utcnow() + datetime.timedelta(hours=2)).date() + datetime.timedelta(days=1)).isoformat()

SHOPS = [  # name, service, price, minutes, customers' language
    ("Car wash", "Full wash", 10, 45, "en"),
    ("Tutor", "Maths lesson", 15, 60, "ar"),
    ("Garage", "Oil change", 25, 45, "fr"),
]
SAY = {
    "en": ("Please book {svc} tomorrow at 10:00", "Please cancel my appointment", "Reminder"),
    "ar": ("احجز لي {svc} غداً الساعة 10:00", "ألغِ موعدي من فضلك", "تذكير"),
    "fr": ("Réservez-moi {svc} demain à 10h00", "Annulez mon rendez-vous s'il vous plaît", "Rappel"),
}
n = [0]


def fresh():
    n[0] += 1
    return f"9617810{RUN}{n[0]:02d}"


def send(pid, frm, **kw):
    code, mid, t0 = wa_msg(pid, frm, **kw)
    return [x for x in wait_reply(t0, frm) if x["body"].get("to", "").lstrip("+") == frm], t0


def sent_to(phone, since):
    return texts([x for x in outbox(since=since) if x["body"].get("to", "").lstrip("+") == phone])


def appts(shop_id):
    q = 'App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id","' + shop_id + '")'
    return tj('echo json_encode(' + q + '->get()->map(fn($a)=>[$a->id,$a->status,$a->starts_at->setTimezone("Asia/Beirut")->format("Y-m-d H:i"),$a->customer?->phone,$a->reminded_at?1:0])->all());')


for i, (name, svc, price, mins, lang) in enumerate(SHOPS):
    suite(f"Booking staff and reminders: {name} (customers write {lang})")
    book, cancel, reminder_word = SAY[lang]
    phone = f"+9617{RUN}{i:02d}9"
    code, b = api("POST", "/admin/merchants", OWN, {"shopName": f"QA {name} B {RUN}", "whatsappNumber": phone, "password": "123456", "businessType": "Other", "subscriptionStatus": "trial", "country": "LB"})
    m = b["data"]; pid = f"PC{RUN}{i:02d}"
    api("PATCH", f"/admin/merchants/{m['id']}/bot", OWN, {"phoneNumberId": pid, "accessToken": "lab-shop-token"})
    api("POST", f"/admin/merchants/{m['id']}/bot/restart", OWN)
    code, lb = login(f"{m['slug']}@merchant.neotalab", "123456"); tok = lb["token"]
    api("PATCH", "/settings/profile", tok, {"business_mode": "appointments", "appointment_capacity": 3, "slot_step_mins": 30, "reminder_mins": 120})
    code, cat = api("POST", "/categories", tok, {"name": "Main"})
    api("POST", "/products", tok, {"name": svc, "price": price, "duration_mins": mins, "category_id": (cat.get("data") or cat)["id"]})
    staff = f"9617820{RUN}{i:02d}"
    code, st = api("POST", "/drivers", tok, {"name": "Desk", "phone": "+" + staff, "whatsappNumber": "+" + staff, "role": "staff"})
    check(f"[{name}] shop, service and a staff member are set up", code == 201, (code, str(st)[:150]))
    # The staff member writes to the shop number: they are inside WhatsApp's 24 hours and get the normal tickets.
    send(pid, staff, text="hi")

    # 1. one message with service, day and time: booked at once, no list of free times first
    c1 = fresh()
    t_book = time.time()
    out, t0 = send(pid, c1, text=book.format(svc=svc, day=DAY))
    mine = [a for a in appts(m["id"]) if a[3] == "+" + c1]
    check(f"[{name}] a customer who names service, day and time is booked at once", len(mine) == 1 and mine[0][1] == "booked" and mine[0][2] == f"{DAY} 10:00", (texts(out)[:1], mine))
    check(f"[{name}] and was not shown a list of free times first", not any("10:00, 10:30" in x or "09:00, 09:30" in x for x in texts(out)), texts(out)[:1])

    # 2. the staff get the booking ticket with Done / No-show
    time.sleep(3)
    staff_msgs = sent_to(staff, t_book)
    ticket = [x for x in staff_msgs if "stf:appt_completed" in x]
    check(f"[{name}] the staff get the booking ticket with Done and No-show", len(ticket) == 1 and "stf:appt_no_show" in ticket[0], staff_msgs)

    # 3. staff tap Done
    aid = mine[0][0] if mine else None
    t1 = time.time()
    send(pid, staff, button=f"stf:appt_completed:{aid}")
    after = [a for a in appts(m["id"]) if a[0] == aid]
    check(f"[{name}] tapping Done closes the booking as completed", after and after[0][1] == "completed", after)

    # 4. a second customer books, then cancels: the staff are told
    c2 = fresh()
    send(pid, c2, text=book.format(svc=svc, day=DAY))
    b2 = [a for a in appts(m["id"]) if a[3] == "+" + c2 and a[1] == "booked"]
    check(f"[{name}] second customer is booked at once too", len(b2) == 1, b2)
    t2 = time.time()
    out, _ = send(pid, c2, text=cancel)
    time.sleep(3)
    left = [a for a in appts(m["id"]) if a[3] == "+" + c2 and a[1] == "booked"]
    check(f"[{name}] the customer cancels", len(left) == 0, (texts(out)[:1], left))
    told = [x for x in sent_to(staff, t2) if "❌" in x or "cancel" in x.lower() or "ألغى" in x or "annul" in x.lower()]
    check(f"[{name}] the staff are told about the cancellation", len(told) >= 1, sent_to(staff, t2))

    # 5. the reminder: queued with the booking, sent in the customer's language, never for a cancelled booking
    queued = tj('echo json_encode(DB::table("jobs")->where("payload","like","%SendAppointmentReminder%")->count());')
    check(f"[{name}] a reminder job is waiting in the queue", queued >= 1, queued)
    c3 = fresh()
    send(pid, c3, text=book.format(svc=svc, day=DAY))
    b3 = [a for a in appts(m["id"]) if a[3] == "+" + c3 and a[1] == "booked"]
    if b3:
        t3 = time.time()
        tinker('(new App\\Jobs\\SendAppointmentReminder("' + b3[0][0] + '"))->handle();')
        time.sleep(2)
        rem = sent_to(c3, t3)
        check(f"[{name}] the reminder arrives in the customer's language", any(reminder_word in x for x in rem), rem)
        t4 = time.time()
        tinker('(new App\\Jobs\\SendAppointmentReminder("' + b3[0][0] + '"))->handle();')
        time.sleep(2)
        check(f"[{name}] a booking is reminded once", not sent_to(c3, t4), sent_to(c3, t4))
    else:
        check(f"[{name}] third customer is booked (for the reminder)", False, appts(m["id"]))
    gone = [a for a in appts(m["id"]) if a[3] == "+" + c2]
    if gone:
        t5 = time.time()
        tinker('(new App\\Jobs\\SendAppointmentReminder("' + gone[0][0] + '"))->handle();')
        time.sleep(2)
        check(f"[{name}] no reminder is sent for a cancelled booking", not sent_to(c2, t5), sent_to(c2, t5))
