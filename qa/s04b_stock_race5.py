"""5 customers race for 1 remaining item (barrier-synchronised)."""
import threading
from lib import *
s = state(); r = s["shops"]["rest"]; RO = r["token"]
suite("Inventory: 5 buyers, 1 item")
bad = 0; placed = 0; N = 5; ROUNDS = 6
for i in range(ROUNDS):
    tinker(f'App\\Models\\Product::withoutGlobalScopes()->whereKey("{r["stock"]}")->update(["stock_quantity"=>1,"is_available"=>true]);')
    convs = []
    for k in range(N):
        code, b = api("POST", "/conversations", RO, {"phone": f"+9618{i:02d}{k}{int(time.time()*10)%100000:05d}", "name": f"R5 {i}{k}"})
        cid = b["data"]["id"]
        api("POST", f"/conversations/{cid}/turns", RO, {"message": "add 1 last kunafa"})
        api("POST", f"/conversations/{cid}/turns", RO, {"message": "add 1 zaatar manousheh"})
        convs.append(cid)
    bar = threading.Barrier(N); res = [None]*N
    def go(k):
        bar.wait()
        res[k] = api("POST", f"/conversations/{convs[k]}/turns", None, {"message": "confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery"}, headers={"Authorization": f"Bearer {RO}"})
    th = [threading.Thread(target=go, args=(k,)) for k in range(N)]
    [t.start() for t in th]; [t.join() for t in th]
    ids = '","'.join(convs)
    st = tj(f'echo json_encode(["stock"=>App\\Models\\Product::withoutGlobalScopes()->find("{r["stock"]}")->stock_quantity,"orders"=>App\\Models\\Order::withoutGlobalScopes()->whereIn("conversation_id",["{ids}"])->count()]);')
    print(f"round {i}: stock={st['stock']} orders={st['orders']} codes={[x[0] for x in res]}")
    placed += st["orders"] >= 1; bad += st["orders"] > 1 or st["stock"] < 0
    time.sleep(8)
check("an order was placed in every 5-way race", placed == ROUNDS, placed)
check(f"no oversell in {ROUNDS} 5-way races", bad == 0, f"{bad} oversold")
