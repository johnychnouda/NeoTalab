"""Timers on the real lab queue, fired early: order nobody accepts, late order."""
from lib import *

s = state(); R = s["shops"]["rest"]; PID = R["phone_id"]; STAFF = "96171222002"
RUN = str(int(time.time()))[-5:]


def say(frm, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    return texts([x for x in settle(t0) if x["body"].get("to", "").lstrip("+") == frm])


def order(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + R["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->latest("number")->first()?->only("id","number","status","promised_at","eta_updates","rating_requested_at"));')


def fire(job, order_id, final=None):
    """Make the queued job for this order due now; returns how many were moved."""
    cond = f'->where("payload","like","%{job}%")->where("payload","like","%{order_id}%")'
    if final is not None:
        cond += f'->where("payload","like","%final\\\\\\";b:{1 if final else 0}%")'
    n = tj('echo json_encode(DB::table("jobs")' + cond + '->update(["available_at"=>now()->subSecond()->timestamp]));')
    return n


suite("Timers: order nobody accepts")
say(STAFF, text="hi")
c = "9617981" + RUN
say(c, text="add 1 zaatar manousheh")
say(c, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
o = order(c)
check("order waits for the shop", o["status"] == "placed", o)
queued = tj('echo json_encode(DB::table("jobs")->where("payload","like","%CheckUnacceptedOrder%")->where("payload","like","%' + o["id"] + '%")->count());')
check("two timers queued (reminder, give up)", queued == 2, queued)
t0 = time.time()
fire("CheckUnacceptedOrder", o["id"], final=False)
out = settle(t0, quiet=2.0, cap=20)
check("reminder goes to the staff", any(x["body"].get("to", "").lstrip("+") == STAFF for x in out), texts(out))
check("order still waiting after the reminder", order(c)["status"] == "placed")
t0 = time.time()
fire("CheckUnacceptedOrder", o["id"], final=True)
out = settle(t0, quiet=2.0, cap=20)
mine = texts([x for x in out if x["body"].get("to", "").lstrip("+") == c])
check("after the wait the order is closed", order(c)["status"] == "rejected", order(c))
check("the customer gets exactly one polite message", len(mine) == 1, mine)
print("  customer told:", mine)

suite("Timers: accepted in time, the give-up timer does nothing")
c2 = "9617982" + RUN
say(c2, text="add 1 zaatar manousheh")
say(c2, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
o2 = order(c2)
code, mid, t0 = wa_msg(PID, STAFF, button=f"stf:accept:{o2['id']}"); settle(t0)
check("staff accepted", order(c2)["status"] == "confirmed")
t0 = time.time(); fire("CheckUnacceptedOrder", o2["id"]); settle(t0, quiet=2.0, cap=15)
check("timers after acceptance change nothing", order(c2)["status"] == "confirmed", order(c2))

suite("Timers: late order")
tinker('App\\Models\\Order::withoutGlobalScopes()->whereKey("' + o2["id"] + '")->update(["promised_at"=>now()->subMinutes(3)]);')
before = order(c2)
t0 = time.time()
n = fire("CheckLateOrder", o2["id"])
out = settle(t0, quiet=2.0, cap=20)
mine = texts([x for x in out if x["body"].get("to", "").lstrip("+") == c2])
after = order(c2)
check("a late timer was queued for the accepted order", n >= 1, n)
check("the late customer gets a new time", len(mine) == 1, mine)
check("the promised time moves later and is counted", after["eta_updates"] == before["eta_updates"] + 1 and after["promised_at"] > before["promised_at"], (before, after))
print("  customer told:", mine)
tinker('App\\Models\\Order::withoutGlobalScopes()->whereKey("' + o2["id"] + '")->update(["promised_at"=>now()->subMinutes(3),"status"=>"delivered","delivered_at"=>now()]);')
t0 = time.time(); fire("CheckLateOrder", o2["id"]); out = settle(t0, quiet=2.0, cap=15)
check("no 'late' message after the order was delivered", not [x for x in out if x["body"].get("to", "").lstrip("+") == c2], texts(out))
failed = tj('echo json_encode(DB::table("failed_jobs")->where("failed_at",">=",now()->subMinutes(5))->count());')
check("no failed timer jobs", failed == 0, failed)
