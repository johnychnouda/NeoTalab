"""Scale lab outage test: customers keep writing while the database or Meta is down for a while.
Workers run like production (docker/queue.sh: a restart loop, --tries=3 --timeout=75). Customers write at about 25 a second
for 25 seconds; the outage starts after 6 seconds and lasts OUT seconds. A message the webhook refused (non-200) is delivered
again with the same id once the outage is over, as Meta does. Then it counts customers never answered, answered twice, wrong carts.
usage: ./scale_env.sh python3 -u s47_outage.py db|meta [OUT_SECONDS]   (scale lab only: 127.0.0.1:8012, fake assistant)"""
import concurrent.futures as cf, os, signal, subprocess, sys, threading
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"], "refusing: not the scale lab"
TARGET = sys.argv[1]; OUT = int(sys.argv[2]) if len(sys.argv) > 2 else 20
LABD = os.path.join(QA, ".lab")
PLAN = [p for p in json.load(open(f"{LAB}/scale/plan.json")) if p["type"] not in ("Salon / Barber / Beauty", "Laundry", "Optics", "Other")]
RUN = int(time.time()) % 10000
SH = os.path.join(LABD, "scale.sh")
MYSQLD = ["/opt/homebrew/bin/mysqld", f"--datadir={LABD}/mysql/data", "--port=3399", f"--socket={LABD}/mysql/m.sock", "--mysqlx=OFF",
          "--max-connections=600", "--innodb-buffer-pool-size=1G", "--bind-address=127.0.0.1", "--default-time-zone=+00:00"]


def sql(q):
    r = subprocess.run(["/opt/homebrew/bin/mysql", "-uroot", "-h127.0.0.1", "-P3399", "-N", "--connect-timeout=3", "neotalab_qa_scale", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


def pid_of(pattern):
    out = subprocess.run(["pgrep", "-f", pattern], capture_output=True, text=True).stdout.split()
    return int(out[0]) if out else None


def stop(target):
    if target == "db":
        os.kill(pid_of("mysqld.*3399"), signal.SIGTERM)
    else:
        os.kill(pid_of("mock_graph.py.*scale/graph.jsonl"), signal.SIGKILL)


def start(target):
    if target == "db":
        subprocess.Popen(MYSQLD, stdout=open(f"{LABD}/mysql/restart.log", "a"), stderr=subprocess.STDOUT, start_new_session=True)
        for _ in range(90):
            if sql("select 1"):
                return
            time.sleep(1)
    else:
        env = dict(os.environ, MOCK_GRAPH_PORT="8049")
        subprocess.Popen([sys.executable, os.path.join(QA, "mock_graph.py"), f"{LABD}/scale/graph.jsonl"], env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        time.sleep(1)


def start_workers(n):
    loop = f'while true; do {SH} php artisan queue:work --sleep=1 --tries=3 --timeout=75 || true; sleep 1; done'
    return [subprocess.Popen(["sh", "-c", loop], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True) for _ in range(n)]


def kill_all(procs):
    for p in procs:
        try:
            os.killpg(p.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
    subprocess.run(["pkill", "-9", "-f", "queue:work"])


for p in PLAN:
    r = sql(f"select name from products where merchant_id='{p['id']}' and is_active=1 and is_available=1 and (track_inventory=0 or stock_quantity>0) and price>0 order by sort_order limit 1")
    p["item"] = r[0][0] if r else None
PLAN = [p for p in PLAN if p["item"]]
sql("delete from jobs; delete from failed_jobs")
suite(f"Outage: {TARGET} down for {OUT}s while customers write")
workers = start_workers(10); time.sleep(5)
sent = []  # (shop, phone, mid, code)
lock = threading.Lock()
t0 = time.time(); phase = {"down_at": None, "up_at": None}


def customer(i):
    time.sleep(i * 0.04)
    s = PLAN[i % len(PLAN)]; ph = f"96179{RUN:04d}{i:04d}"
    code, mid, _ = wa_msg(s["pid"], ph, text=f"add 1 {s['item']}")
    with lock:
        sent.append((s, ph, mid, code))


def chaos():
    time.sleep(6); phase["down_at"] = time.time() - t0; stop(TARGET)
    time.sleep(OUT); start(TARGET); phase["up_at"] = time.time() - t0


N = 625
th = threading.Thread(target=chaos); th.start()
with cf.ThreadPoolExecutor(40) as ex:
    list(ex.map(customer, range(N)))
th.join()
codes = [c for *_, c in sent]
refused = [(s, ph, mid) for s, ph, mid, c in sent if c != 200]
print(f"  {len(sent)} customers wrote; outage from {phase['down_at']:.0f}s to {phase['up_at']:.0f}s; webhook refused {len(refused)} (codes {sorted(set(c for c in codes if c != 200))})")
for s, ph, mid in refused:  # Meta delivers a refused message again
    wa_msg(s["pid"], ph, text=f"add 1 {s['item']}", mid=mid)
t1 = time.time(); done_at = None
while time.time() - t0 < 600:
    n = sql(f"select count(distinct to_number) from whatsapp_messages where direction='outbound' and to_number like '+96179{RUN:04d}%'")
    if n and int(n[0][0]) >= N:
        done_at = time.time() - t0; break
    time.sleep(3)
time.sleep(2); kill_all(workers)
replies = {r[0]: int(r[1]) for r in sql(f"select to_number, count(*) from whatsapp_messages where direction='outbound' and to_number like '+96179{RUN:04d}%' group by 1")}
cart = {r[0]: float(r[1]) for r in sql(f"select cu.phone, sum(ci.quantity) from cart_items ci join carts ca on ca.id=ci.cart_id join conversations co on co.id=ca.conversation_id join customers cu on cu.id=co.customer_id where cu.phone like '+96179{RUN:04d}%' group by 1")}
phones = [ph for _, ph, _, _ in sent]
unanswered = [p for p in phones if "+" + p not in replies]
twice = [p for p in phones if replies.get("+" + p, 0) > 1]
nocart = [p for p in phones if cart.get("+" + p) != 1.0]
print(f"  all answered after {'%.0f s' % done_at if done_at else 'NOT within 600 s'}; failed jobs {sql('select count(*) from failed_jobs')[0][0] if sql('select 1') else '?'}")
check(f"{TARGET} outage: every customer was answered in the end", not unanswered, f"{len(unanswered)} never answered")
check(f"{TARGET} outage: nobody was answered twice", not twice, f"{len(twice)} answered twice")
check(f"{TARGET} outage: every cart holds exactly 1 of the item asked for", not nocart, f"{len(nocart)} wrong carts")
