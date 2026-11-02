"""Scale lab: many customers order at the same moment. Each customer is a WhatsApp number that says "add 2 <item>", then
"confirm | Hamra | address | cash | USD | | delivery" (all customers' messages released together). Checked in the database:
one order per customer, unique order numbers, every line priced from the catalog, totals add up, stock never oversold.
usage: s42_concurrency.py one N [stock]   |   s42_concurrency.py all PER_SHOP"""
import concurrent.futures as cf, statistics, subprocess, sys, threading
from lib import *
assert "neotalab_qa_scale" in ENV["DB_DATABASE"]
PLAN = json.load(open(f"{LAB}/scale/plan.json"))


def sql(q):
    r = subprocess.run(["/opt/homebrew/bin/mysql", "-uroot", "-h127.0.0.1", "-P3399", "-N", "neotalab_qa_scale", "-e", q], capture_output=True, text=True)
    return [l.split("\t") for l in r.stdout.strip().splitlines() if l]


def one(q): return sql(q)[0][0]


def blast(jobs, workers):
    """jobs: list of (pid, phone, text). Released together; returns (codes, seconds)."""
    gate = threading.Event(); codes = []

    def go(j):
        gate.wait(); c, _, _ = wa_msg(j[0], j[1], text=j[2], name="Load " + j[1][-4:]); return c
    with cf.ThreadPoolExecutor(workers) as ex:
        futs = [ex.submit(go, j) for j in jobs]; time.sleep(0.5); t0 = time.time(); gate.set()
        codes = [f.result() for f in futs]
    return codes, time.time() - t0


def wait_for(cond, cap, step=0.5):
    t0 = time.time()
    while time.time() - t0 < cap:
        if cond(): return time.time() - t0
        time.sleep(step)
    return None


def products_of(mid, n):
    return sql(f"select id,name,price from products where merchant_id='{mid}' and is_active=1 and name not like '%Pack of 3%' order by sort_order limit {n}")


def verify(label, mids, phones, expect_orders, run_tag):
    inl = ",".join(f"'{m}'" for m in mids); pl = ",".join(f"'+{p}'" for p in phones)
    cust = f"select id from customers where phone in ({pl}) and merchant_id in ({inl})"
    got = int(one(f"select count(*) from orders where customer_id in ({cust})"))
    dup = int(one(f"select count(*) from (select customer_id from orders where customer_id in ({cust}) group by customer_id having count(*)>1) x"))
    dupnum = int(one(f"select count(*) from (select merchant_id,number from orders where merchant_id in ({inl}) and number is not null group by merchant_id,number having count(*)>1) x"))
    wrong_price = int(one(f"select count(*) from order_items i join products p on p.id=i.product_id join orders o on o.id=i.order_id where o.customer_id in ({cust}) and i.unit_price<>p.price"))
    wrong_line = int(one(f"select count(*) from order_items i join orders o on o.id=i.order_id where o.customer_id in ({cust}) and abs(i.item_total - i.unit_price*i.quantity)>0.005"))
    wrong_total = int(one(f"select count(*) from orders o where o.customer_id in ({cust}) and abs(o.subtotal - (select coalesce(sum(item_total),0) from order_items where order_id=o.id))>0.005"))
    wrong_sum = int(one(f"select count(*) from orders o where o.customer_id in ({cust}) and abs(o.total - (o.subtotal + o.delivery_fee))>0.005"))
    wrong_shop = int(one(f"select count(*) from order_items i join orders o on o.id=i.order_id join products p on p.id=i.product_id where o.customer_id in ({cust}) and p.merchant_id<>o.merchant_id"))
    check(f"{label}: {expect_orders} customers, {expect_orders} orders placed (got {got})", got == expect_orders, got)
    check(f"{label}: no customer got two orders", dup == 0, dup)
    check(f"{label}: no order number used twice in a shop", dupnum == 0, dupnum)
    check(f"{label}: every line priced as in the catalog", wrong_price == 0, wrong_price)
    check(f"{label}: every line total = price x quantity; every order subtotal = its lines; total = subtotal + delivery", wrong_line == 0 and wrong_total == 0 and wrong_sum == 0, (wrong_line, wrong_total, wrong_sum))
    check(f"{label}: no order contains another shop's item", wrong_shop == 0, wrong_shop)
    return got


mode = sys.argv[1]; RUN = str(int(time.time()))[-6:]
failed0 = int(one("select count(*) from failed_jobs"))
if mode == "one":
    N = int(sys.argv[2]); stock = int(sys.argv[3]) if len(sys.argv) > 3 else 0
    shop = next(p for p in PLAN if p["products"] == 120 and p["type"] == "Restaurant / Café") if any(p["products"] == 120 and p["type"] == "Restaurant / Café" for p in PLAN) else next(p for p in PLAN if p["products"] == 120)
    prods = products_of(shop["id"], 12); suite(f"One shop, {N} customers at once ({shop['type']}, {shop['products']} items)")
    if stock:
        sql(f"update products set track_inventory=1, stock_quantity={stock}, is_available=1 where id='{prods[0][0]}'")
    phones = [f"9617{int(RUN[-5:]) % 100000:05d}{i:04d}"[:13] for i in range(N)]
    phones = [f"96179{RUN[-4:]}{i:04d}" for i in range(N)]
    # step 1: carts (stock test: everybody wants 1 of the limited item)
    adds = [(shop["pid"], ph, f"add 1 {prods[0][1]}" if stock else f"add {1 + i % 3} {prods[i % len(prods)][1]}") for i, ph in enumerate(phones)]
    codes, secs = blast(adds, min(N, 150))
    check(f"all {N} 'add to cart' messages accepted by the webhook (200)", all(c == 200 for c in codes), set(codes))
    filled = wait_for(lambda: int(one(f"select count(distinct c.id) from carts c join customers cu on cu.id=c.customer_id join cart_items ci on ci.cart_id=c.id where cu.merchant_id='{shop['id']}' and cu.phone like '+96179{RUN[-4:]}%'")) >= N, 240) if False else None
    time.sleep(2)
    wait_for(lambda: int(one(f"select count(*) from customers where merchant_id='{shop['id']}' and phone like '+96179{RUN[-4:]}%'")) >= N, 240)
    wait_for(lambda: int(one("select count(*) from jobs where available_at <= unix_timestamp()")) == 0, 240); time.sleep(2)
    confirm = [(shop["pid"], ph, "confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery") for ph in phones]
    t_send = time.time()
    codes, secs = blast(confirm, min(N, 150))
    check(f"all {N} 'confirm' messages accepted by the webhook (200)", all(c == 200 for c in codes), set(codes))
    print(f"  webhook accepted {N} confirms in {secs:.1f}s")
    ok = wait_for(lambda: int(one(f"select count(*) from orders o join customers c on c.id=o.customer_id where o.merchant_id='{shop['id']}' and c.phone like '+96179{RUN[-4:]}%'")) >= (min(N, stock) if stock else N), 600)
    wait_for(lambda: int(one("select count(*) from jobs where available_at <= unix_timestamp()")) == 0, 300); time.sleep(3)
    lat = sql(f"set time_zone='+00:00'; select unix_timestamp(o.created_at) from orders o join customers c on c.id=o.customer_id where o.merchant_id='{shop['id']}' and c.phone like '+96179{RUN[-4:]}%'")
    lat = [[float(x[0]) - t_send] for x in lat]
    lats = sorted(float(x[0]) for x in lat) if lat else []
    if lats: print(f"  confirm -> order saved: p50 {statistics.median(lats):.1f}s  p95 {lats[int(len(lats)*0.95)-1]:.1f}s  p99 {lats[int(len(lats)*0.99)-1]:.1f}s  max {lats[-1]:.1f}s")
    got = verify(f"{N} at once", [shop["id"]], phones, min(N, stock) if stock else N, RUN)
    if stock:
        left = float(one(f"select stock_quantity from products where id='{prods[0][0]}'"))
        sold = float(one(f"select coalesce(sum(i.quantity),0) from order_items i join orders o on o.id=i.order_id where i.product_id='{prods[0][0]}' and o.status not in ('cancelled')"))
        check(f"limited item: {stock} in stock, {N} customers want 1 each: exactly {stock} orders, stock ends at 0, never negative", left == 0 and got == stock, (left, got, sold))
else:
    PER = int(sys.argv[2]); suite(f"All 120 shops at once, {PER} customers each ({120 * PER} orders)")
    jobs_add = []; jobs_conf = []; phones = []; mids = [p["id"] for p in PLAN]
    for si, shop in enumerate(PLAN):
        prods = products_of(shop["id"], 10)
        for k in range(PER):
            ph = f"96178{RUN[-3:]}{si:03d}{k:02d}"; phones.append(ph)
            jobs_add.append((shop["pid"], ph, f"add {1 + k % 3} {prods[(si + k) % len(prods)][1]}"))
            jobs_conf.append((shop["pid"], ph, "confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery"))
    random.shuffle(jobs_add) if False else None
    codes, secs = blast(jobs_add, 150); check(f"{len(jobs_add)} carts: webhook accepted all (200)", all(c == 200 for c in codes), set(codes))
    wait_for(lambda: int(one("select count(*) from jobs where available_at <= unix_timestamp()")) == 0, 300); time.sleep(3)
    codes, secs = blast(jobs_conf, 150); check(f"{len(jobs_conf)} confirms: webhook accepted all (200)", all(c == 200 for c in codes), set(codes)); print(f"  accepted in {secs:.1f}s")
    wait_for(lambda: int(one(f"select count(*) from orders o join customers c on c.id=o.customer_id where c.phone like '+96178{RUN[-3:]}%'")) >= len(phones), 900)
    wait_for(lambda: int(one("select count(*) from jobs where available_at <= unix_timestamp()")) == 0, 300); time.sleep(3)
    verify("120 shops at once", mids, phones, len(phones), RUN)
    # each order is in the shop its customer wrote to
    wrong = int(one(f"select count(*) from orders o join customers c on c.id=o.customer_id where c.phone like '+96178{RUN[-3:]}%' and c.merchant_id<>o.merchant_id"))
    check("every order is in the shop its customer wrote to", wrong == 0, wrong)
failed1 = int(one("select count(*) from failed_jobs"))
check("no background job failed during the run", failed1 == failed0, failed1 - failed0)
