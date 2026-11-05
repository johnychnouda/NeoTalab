"""Scale lab: team members and customers press buttons that are not theirs, through the real webhook and queue.
For 20 shops: a driver acts on another driver's order, a staff member of shop A presses Accept / Delivered on shop B's
order (written to A's number and to B's number), a customer forges staff and driver buttons on their own order. A hit is
any order whose status or driver changed. Scale lab only: ./scale_env.sh python3 s50_role_attacks.py"""
import subprocess
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"], "refusing: not the scale lab"
s = state(); SHOPS = [v for k, v in sorted(s["scale"].items())][:20]
RUN = str(int(time.time()))[-4:]
workers = [subprocess.Popen([LABSH, "php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--timeout=75"],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True) for _ in range(4)]


def press(pid, frm, button):
    wa_msg(pid, frm, button=button)


try:
    suite("Roles: buttons that are not yours (20 shops)")
    # Per shop: one staff, two drivers, one waiting order assigned to driver 1, and its customer.
    setup = tj('''$out=[]; foreach(%s as $i=>$mid){ $m=App\\Models\\Merchant::find($mid); app(App\\Support\\CurrentTenant::class)->set($m);
        $mk=fn($p,$r,$n)=>App\\Models\\Driver::withoutGlobalScopes()->updateOrCreate(["merchant_id"=>$m->id,"phone"=>$p],["name"=>$n,"role"=>$r,"whatsapp_number"=>$p,"password"=>"secret123","status"=>"on_duty","availability"=>"busy","is_active"=>true]);
        $st=$mk("+96175%s".str_pad($i,2,"0",STR_PAD_LEFT)."1","staff","QA Staff $i"); $d1=$mk("+96175%s".str_pad($i,2,"0",STR_PAD_LEFT)."2","driver","QA D1 $i"); $d2=$mk("+96175%s".str_pad($i,2,"0",STR_PAD_LEFT)."3","driver","QA D2 $i");
        $o=App\\Models\\Order::withoutGlobalScopes()->where("merchant_id",$m->id)->with("customer")->latest("created_at")->first();
        $o->forceFill(["status"=>"assigned","driver_id"=>$d1->id])->save(); $d1->forceFill(["active_order_id"=>$o->id])->save();
        $out[]=["mid"=>$m->id,"staff"=>$st->phone,"d1"=>$d1->phone,"d2"=>$d2->phone,"order"=>$o->id,"cust"=>$o->customer->phone]; }
        echo json_encode($out);''' % (json.dumps([x["id"] for x in SHOPS]), RUN, RUN, RUN))
    for x, shop in zip(setup, SHOPS):
        x["pid"] = shop["pid"]
    ids = json.dumps([x["order"] for x in setup])
    before = tj(f'echo json_encode(App\\Models\\Order::withoutGlobalScopes()->whereIn("id",{ids})->get(["id","status","driver_id"])->keyBy("id"));')

    for i, a in enumerate(setup):
        b = setup[(i + 1) % len(setup)]
        press(a["pid"], a["d2"].lstrip("+"), f"drv:delivered:{a['order']}")          # other driver, same shop
        press(a["pid"], a["d2"].lstrip("+"), f"drv:picked_up:{a['order']}")
        press(a["pid"], a["staff"].lstrip("+"), f"stf:delivered:{b['order']}")      # staff of A, B's order, A's number
        press(b["pid"], a["staff"].lstrip("+"), f"stf:accept:{b['order']}")         # staff of A, on B's number
        press(b["pid"], a["d1"].lstrip("+"), f"drv:delivered:{b['order']}")         # driver of A, on B's number
        press(a["pid"], a["cust"].lstrip("+"), f"stf:delivered:{a['order']}")       # customer forges staff button
        press(a["pid"], a["cust"].lstrip("+"), f"drv:delivered:{a['order']}")       # customer forges driver button
        press(a["pid"], a["cust"].lstrip("+"), f"stf:wh_ok:{a['order']}")           # customer confirms own payment
    deadline = time.time() + 120
    while time.time() < deadline and int(tj('echo json_encode(DB::table("jobs")->count());')) > 0:
        time.sleep(2)
    time.sleep(3)
    after = tj(f'echo json_encode(App\\Models\\Order::withoutGlobalScopes()->whereIn("id",{ids})->get(["id","status","driver_id"])->keyBy("id"));')
    changed = [(k, before[k], after.get(k)) for k in before if before[k] != after.get(k)]
    check("160 forged or foreign button presses: no order changed status or driver", not changed, changed[:5])
    check("no background job failed", int(tj('echo json_encode(DB::table("failed_jobs")->count());')) == 0)

    # Control: the right driver can deliver, so the attacks above failed for the right reason.
    a = setup[0]
    press(a["pid"], a["d1"].lstrip("+"), f"drv:picked_up:{a['order']}")
    time.sleep(6)
    st = tj(f'echo json_encode(App\\Models\\Order::withoutGlobalScopes()->find("{a["order"]}")->status);')
    check("control: the assigned driver's own Picked up works", st == "picked_up", st)
finally:
    for w in workers:
        w.kill()
