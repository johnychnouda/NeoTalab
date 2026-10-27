"""Workflow 3: salon bookings through WhatsApp (lab stand-in AI grammar: 'slots|book {service} {date} {time}')."""
import threading, datetime
from lib import *

s = state(); sl = s["shops"]["salon"]; PID = sl["phone_id"]
RUN = str(int(time.time()))[-5:]
# A fresh day per run: earlier runs leave bookings behind in the lab.
day = (datetime.date.today() + datetime.timedelta(days=100 + int(RUN) % 3000)).isoformat()
past = (datetime.date.today() - datetime.timedelta(days=1)).isoformat()


def say(frm, text):
    code, mid, t0 = wa_msg(PID, frm, text=text)
    return texts([x for x in settle(t0) if x["body"].get("to", "").lstrip("+") == frm])


def booked(slot=None):
    q = 'App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id","' + sl["id"] + '")->where("status","booked")'
    return tj('echo json_encode(' + q + '->get(["customer_id","starts_at","service_name","price","provider_id"])->map(fn($a)=>[$a->customer_id,$a->starts_at->setTimezone("Asia/Beirut")->format("Y-m-d H:i"),$a->service_name,$a->price,$a->provider_id]));')


suite("W3 salon bookings")
c = ["96176400" + RUN + str(i) for i in range(6)]
out = say(c[0], f"slots haircut {day}")
check("free slots listed for the day", any("10:00" in x for x in out), out)
out = say(c[0], f"book haircut {day} 10:00")
b = [x for x in booked() if x[1] == f"{day} 10:00"]
check("customer 1 books 10:00", len(b) == 1, (out, b))
check("booking stores the service price 15.00", b and b[0][3] == "15.00", b)
out = say(c[1], f"book haircut {day} 10:00")
b = [x for x in booked() if x[1] == f"{day} 10:00"]
# The salon has two stylists: customer 2 gets the other one. The rule is one booking per stylist per time.
check("customer 2 gets the other stylist at 10:00 (never the same one)", len(b) == 2 and len({x[4] for x in b}) == 2, (out, b))
print("  customer 2 told:", out)

# 4 customers race for 11:00
res = [None] * 4
bar = threading.Barrier(4)
def go(i):
    bar.wait()
    res[i] = wa_msg(PID, c[2 + i] if 2 + i < len(c) else "96176499" + RUN + str(i), text=f"book haircut {day} 11:00")
th = [threading.Thread(target=go, args=(i,)) for i in range(4)]
[t.start() for t in th]; [t.join() for t in th]
settle(min(x[2] for x in res), quiet=2.0, cap=20)
b = [x for x in booked() if x[1] == f"{day} 11:00"]
# Two stylists, four customers: exactly two bookings, one per stylist.
check("4 customers racing for 11:00: one booking per stylist (2 of 4)", len(b) == 2 and len({x[4] for x in b}) == 2, b)

out = say(c[0], f"book haircut {past} 10:00")
check("past date refused", not [x for x in booked() if x[1].startswith(past)], out)
out = say(c[1], f"book haircut {day} 23:30")
check("after-hours time refused", not [x for x in booked() if x[1] == f"{day} 23:30"], out)
out = say(c[0], "cancel my appointment")
print("  cancel reply:", out)
b = [x for x in booked() if x[1] == f"{day} 10:00"]
check("customer 1 cancels; only the other stylist keeps 10:00", len(b) == 1, (out, b))
out = say(c[3], f"book haircut {day} 10:00")
b = [x for x in booked() if x[1] == f"{day} 10:00"]
check("freed stylist can be booked at 10:00 by another customer", len(b) == 2 and len({x[4] for x in b}) == 2, (out, b))
# Same customer asks again for a time they already hold: the second booking must be refused.
out = say(c[1], f"book haircut {day} 10:00")
mine = [x for x in booked() if x[0] == tj('echo json_encode(App\\Models\\Customer::withoutGlobalScopes()->where("phone","+' + c[1] + '")->value("id"));') and x[1] == f"{day} 10:00"]
check("a customer who already has 10:00 cannot book 10:00 again", len(mine) == 1, (out, mine))
code, body = api("GET", "/appointments", sl["token"])
check("salon dashboard lists the bookings", code == 200 and day in json.dumps(body), code)
code, body = api("GET", "/appointments", s["shops"]["rest"]["token"])
check("restaurant cannot see salon bookings", code == 200 and day not in json.dumps(body), code)
out = say(c[3] if len(c) > 3 else c[1], "add 1 haircut")
check("a service cannot be put in a cart", not tj('echo json_encode(App\\Models\\CartItem::withoutGlobalScopes()->whereHas("product", fn($q)=>$q->withoutGlobalScopes()->where("duration_mins",">",0))->exists());'), out)
