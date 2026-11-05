"""Scale lab recovery test: what happens to customers' messages when the queue workers are killed mid-job.
Workers run with production's flags (--tries=3 --timeout=75, retry_after 150). Each customer writes "add 1 <item>" once.
control N : N customers, workers left alone (the baseline: replies per customer, cart quantity)
kill N    : N customers, every worker is killed with SIGKILL while jobs run, then fresh workers start
Reports how long until every customer was answered, customers never answered, customers answered twice, wrong carts.
Scale lab only (127.0.0.1:8012, fake assistant). usage: ./scale_env.sh python3 -u s46_recovery.py kill 200"""
import concurrent.futures as cf, os, signal, subprocess, sys
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"], "refusing: not the scale lab"
MODE, N = sys.argv[1], int(sys.argv[2])
PLAN = [p for p in json.load(open(f"{LAB}/scale/plan.json")) if p["type"] not in ("Salon / Barber / Beauty", "Laundry", "Optics", "Other")]
RUN = int(time.time()) % 10000
SH = os.path.join(QA, ".lab", "scale.sh")


def sql(q):
    r = subprocess.run(["/opt/homebrew/bin/mysql", "-uroot", "-h127.0.0.1", "-P3399", "-N", "neotalab_qa_scale", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


def start_workers(n):
    return [subprocess.Popen([SH, "php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--timeout=75"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True) for _ in range(n)]


def kill_all(procs):
    for p in procs:
        try:
            os.killpg(p.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    subprocess.run(["pkill", "-9", "-f", "queue:work"])


def ready():
    return int(sql("select count(*) from jobs")[0][0])


for p in PLAN:  # an orderable first item per shop
    r = sql(f"select name from products where merchant_id='{p['id']}' and is_active=1 and is_available=1 and (track_inventory=0 or stock_quantity>0) and price>0 order by sort_order limit 1")
    p["item"] = r[0][0] if r else None
PLAN = [p for p in PLAN if p["item"]]
sql("delete from jobs; delete from failed_jobs")
suite(f"Recovery: {MODE}, {N} customers")
workers = start_workers(int(os.environ.get("S46_WORKERS", "10"))); time.sleep(4)
custs = [(PLAN[i % len(PLAN)], f"96178{RUN:04d}{i:04d}") for i in range(N)]
t0 = time.time()
with cf.ThreadPoolExecutor(40) as ex:
    codes = list(ex.map(lambda c: wa_msg(c[0]["pid"], c[1], text=f"add 1 {c[0]['item']}")[0], custs))
check(f"all {N} messages accepted by the webhook", all(c == 200 for c in codes), sorted(set(codes)))
if MODE == "kill":
    while ready() > N * 0.6 and time.time() - t0 < 60:  # kill while about 40% are done and others are running
        time.sleep(0.05)
    left = ready()
    kill_all(workers)
    time.sleep(2)
    stuck = sql("select count(*) from jobs where reserved_at is not null")[0][0]
    print(f"  workers killed with {left} jobs still queued, {stuck} of them reserved by a dead worker")
    workers = start_workers(int(os.environ.get("S46_WORKERS", "10")))
done_at = None
while time.time() - t0 < 420:
    n = int(sql(f"select count(distinct to_number) from whatsapp_messages where direction='outbound' and created_at >= from_unixtime({int(t0) - 3}) and to_number like '+96178{RUN:04d}%'")[0][0])
    if n >= N:
        done_at = time.time() - t0; break
    time.sleep(3)
kill_all(workers)
rows = sql(f"select to_number, count(*) from whatsapp_messages where direction='outbound' and to_number like '+96178{RUN:04d}%' group by 1")
replies = {r[0]: int(r[1]) for r in rows}
cart = {r[0]: float(r[1]) for r in sql(f"select cu.phone, sum(ci.quantity) from cart_items ci join carts ca on ca.id=ci.cart_id join conversations co on co.id=ca.conversation_id join customers cu on cu.id=co.customer_id where cu.phone like '+96178{RUN:04d}%' group by 1")}
unanswered = [p for _, p in custs if "+" + p not in replies]
counts = sorted(set(replies.values()))
base = max(set(replies.values()), key=list(replies.values()).count) if replies else 0
twice = [p for p, c in replies.items() if c > base]
# A customer whose worker died is told "interrupted, please resend" (D47). Their cart is right if it holds 0 (nothing done)
# or 1 (the item was added before the worker died, and the reply then shows it so they do not add it twice).
told = {}  # phone -> does the reply list the cart
for to, payload in sql(f"select to_number, payload from whatsapp_messages where direction='outbound' and to_number like '+96178{RUN:04d}%'"):
    body = json.loads(payload)
    text = body.get("text", {}).get("body") or body.get("interactive", {}).get("body", {}).get("text") or ""
    if any(w in text for w in ("interrupted", "interrompu", "انقطعت")):
        told[to] = told.get(to, False) or any(w in text for w in ("Your cart:", "Votre panier :", "سلّتك:"))
wrong = [p for _, p in custs if ("+" + p in told and cart.get("+" + p, 0.0) not in (0.0, 1.0)) or ("+" + p not in told and cart.get("+" + p) != 1.0)]
unshown = [p for _, p in custs if "+" + p in told and cart.get("+" + p) == 1.0 and not told["+" + p]]
print(f"  customers told to resend: {len(told)}; of them with the item already in the cart: {sum(1 for p in told if cart.get(p) == 1.0)}")
print(f"  everyone answered after: {'%.0f s' % done_at if done_at else 'NOT within 420 s'}; replies per customer seen: {counts} (usual {base}); failed jobs: {sql('select count(*) from failed_jobs')[0][0]}")
check(f"{MODE}: every customer was answered", not unanswered, f"{len(unanswered)} unanswered")
check(f"{MODE}: nobody was answered twice", not twice, f"{len(twice)} got more than {base} replies")
check(f"{MODE}: every cart holds exactly 1 of the item asked for (0 or 1 for a customer told to resend)", not wrong, f"{len(wrong)} wrong carts")
if told:
    check(f"{MODE}: a customer told to resend whose item was already added sees it in the reply", not unshown, unshown[:5])
