"""Bookings on shop types other than a salon (real AI, owner-approved; the queue worker must be started
with lab-ai.sh). Each shop gets one bookable service, then customers ask for free times, book, try to
overbook, and cancel, in English, Arabic and French. Checked against the database, not only the reply.
Lab only."""
import datetime, re
from lib import *

s = state(); OWN = s["owner"]
RUN = str(int(time.time()))[-5:]
# A fresh day per run: earlier runs leave bookings behind in the lab.
DAY = (datetime.date.today() + datetime.timedelta(days=100 + int(RUN) % 3000)).isoformat()

# name, business type, service, price, minutes, capacity, mode, language of the customers
SHOPS = [
    ("Car wash", "Other", "Full wash", 10, 45, 2, "appointments", "en"),
    ("Photographer", "Other", "Portrait session", 50, 60, 1, "appointments", "fr"),
    ("Tutor", "Other", "Maths lesson", 15, 60, 1, "appointments", "ar"),
    ("Garage", "Other", "Oil change", 25, 45, 3, "appointments", "fr"),
    ("Phone repair", "Electronics / Phones", "Screen check", 5, 30, 1, "both", "en"),
    ("Pet grooming", "Pet Shop", "Dog bath", 20, 60, 2, "both", "ar"),
    ("Restaurant tables", "Restaurant / Café", "Table for four", 1, 90, 5, "both", "fr"),  # price 1: the shop cannot save a service at 0
    ("Spa", "Salon / Barber / Beauty", "Massage", 40, 60, 2, "appointments", "ar"),
]
SAY = {
    "en": {"slots": "What times are free for {svc} on {day}?", "book": "Please book {svc} on {day} at 10:00", "cancel": "Please cancel my appointment"},
    "ar": {"slots": "ما هي الأوقات المتاحة لـ {svc} يوم {day}؟", "book": "احجز لي {svc} يوم {day} الساعة 10:00", "cancel": "ألغِ موعدي من فضلك"},
    "fr": {"slots": "Quelles heures sont libres pour {svc} le {day} ?", "book": "Réservez-moi {svc} le {day} à 10h00", "cancel": "Annulez mon rendez-vous s'il vous plaît"},
}
n = [0]


def fresh():
    n[0] += 1
    return f"9617800{RUN}{n[0]:02d}"


def say(shop, frm, text):
    code, mid, t0 = wa_msg(shop["pid"], frm, text=text)
    return " || ".join(texts([x for x in wait_reply(t0, frm) if x["body"].get("to", "").lstrip("+") == frm]))


def book(shop, frm, words, svc):
    """What a customer does: ask to book; when the bot answers with the free times instead, pick 10:00."""
    out = say(shop, frm, words["book"].format(svc=svc, day=DAY))
    if not [x for x in booked(shop) if x[0] == f"{DAY} 10:00" and x[3] == "+" + frm]:
        out += " >> " + say(shop, frm, "10:00")
    return out


def booked(shop):
    q = 'App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("status","booked")'
    return tj('echo json_encode(' + q + '->get()->map(fn($a)=>[$a->starts_at->setTimezone("Asia/Beirut")->format("Y-m-d H:i"),$a->service_name,(string)$a->price,$a->customer?->phone])->all());')


def make(i, name, btype, svc, price, mins, cap, mode):
    phone = f"+9617{RUN}{i:02d}8"
    code, b = api("POST", "/admin/merchants", OWN, {"shopName": f"QA {name} {RUN}", "whatsappNumber": phone, "password": "123456", "businessType": btype, "subscriptionStatus": "trial", "country": "LB"})
    if code != 201:
        return None, (code, str(b)[:200])
    m = b["data"]; pid = f"PB{RUN}{i:02d}"
    api("PATCH", f"/admin/merchants/{m['id']}/bot", OWN, {"phoneNumberId": pid, "accessToken": "lab-shop-token"})
    api("POST", f"/admin/merchants/{m['id']}/bot/restart", OWN)
    code, lb = login(f"{m['slug']}@merchant.neotalab", "123456")
    tok = lb.get("token") if isinstance(lb, dict) else None
    if not tok:
        return None, ("login", code)
    code, p = api("PATCH", "/settings/profile", tok, {"business_mode": mode, "appointment_capacity": cap, "slot_step_mins": 30, "reminder_mins": 120})
    code2, cat = api("POST", "/categories", tok, {"name": "Main"})
    cat_id = (cat.get("data") or cat).get("id")
    code3, it = api("POST", "/products", tok, {"name": svc, "price": price, "duration_mins": mins, "category_id": cat_id})
    extra = None
    if mode == "both":
        code4, pr = api("POST", "/products", tok, {"name": "Gift card", "price": 10, "category_id": cat_id})
        extra = (pr.get("data") or pr).get("id")
    return {"id": m["id"], "pid": pid, "token": tok, "svc": svc, "cap": cap, "mode": mode, "extra": extra}, (code, code2, code3)


verdict = {}
for i, (name, btype, svc, price, mins, cap, mode, lang) in enumerate(SHOPS):
    suite(f"Bookings: {name} ({btype}, {mode}, customers write {lang})")
    shop, info = make(i, name, btype, svc, price, mins, cap, mode)
    ok_all = []

    def rec(label, ok, detail=""):
        ok_all.append(bool(ok))
        check(f"[{name}] {label}", ok, detail)

    rec("owner sets the shop up with a bookable service", shop is not None, info)
    if not shop:
        verdict[name] = False
        continue
    words = SAY[lang]
    time_slot = f"{DAY} 10:00"

    c1 = fresh()
    out = say(shop, c1, words["slots"].format(svc=svc, day=DAY))
    rec("asked for free times: the reply lists some (10:00 among them)", "10:00" in out or "10h00" in out or "١٠" in out, out[:200])

    out = book(shop, c1, words, svc)
    b = [x for x in booked(shop) if x[0] == time_slot]
    rec("booked: one appointment at 10:00 with the service and its price", len(b) == 1 and b[0][1].lower() == svc.lower() and float(b[0][2]) == float(price), (out[:200], b))

    # fill the remaining places at 10:00, then one more customer than the shop can serve
    for _ in range(cap - 1):
        book(shop, fresh(), words, svc)
    b = [x for x in booked(shop) if x[0] == time_slot]
    rec(f"{cap} customers fit at 10:00", len(b) == cap, (len(b), cap))
    out = book(shop, fresh(), words, svc)
    b = [x for x in booked(shop) if x[0] == time_slot]
    rec("one more customer than the shop can serve is not booked at 10:00", len(b) == cap, (len(b), out[:200]))

    out = say(shop, c1, words["cancel"])
    b = [x for x in booked(shop) if x[0] == time_slot and x[3] == "+" + c1]
    rec("cancelling frees that customer's booking", len(b) == 0, (out[:200], b))

    if mode == "both":
        c2 = fresh()
        say(shop, c2, "I want 1 Gift card" if lang == "en" else ("أريد 1 Gift card" if lang == "ar" else "Je veux 1 Gift card"))
        lines = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("phone","+' + c2 + '")->first();'
                   '$cv=$c?App\\Models\\Conversation::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null;'
                   '$ct=$cv?App\\Models\\Cart::withoutGlobalScopes()->where("conversation_id",$cv->id)->where("status","open")->first():null;'
                   'echo json_encode($ct?App\\Models\\CartItem::withoutGlobalScopes()->where("cart_id",$ct->id)->with(["product"=>fn($q)=>$q->withoutGlobalScopes()])->get()->map(fn($i)=>[$i->product->name,$i->quantity])->all():[]);')
        rec("a shop that does both still takes an ordinary order", any("gift" in l[0].lower() for l in lines), lines)
        say(shop, c2, f"add 1 {svc}")
        lines = tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("phone","+' + c2 + '")->first();'
                   '$cv=$c?App\\Models\\Conversation::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null;'
                   '$ct=$cv?App\\Models\\Cart::withoutGlobalScopes()->where("conversation_id",$cv->id)->where("status","open")->first():null;'
                   'echo json_encode($ct?App\\Models\\CartItem::withoutGlobalScopes()->where("cart_id",$ct->id)->with(["product"=>fn($q)=>$q->withoutGlobalScopes()])->get()->map(fn($i)=>[$i->product->name,$i->quantity])->all():[]);')
        rec("a service cannot be put in a cart", not any(svc.lower() in l[0].lower() for l in lines), lines)
    verdict[name] = all(ok_all)

print("\nSUMMARY")
for k, v in verdict.items():
    print(("  works     " if v else "  CHECK     ") + k)
