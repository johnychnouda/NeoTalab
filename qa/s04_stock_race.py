"""Pricing/inventory: two customers check out the last item at the same moment."""
import sys, threading
from lib import *

s = state(); r = s["shops"]["rest"]; RO = r["token"]
ROUNDS = int(sys.argv[1]) if len(sys.argv) > 1 else 10
suite("Inventory: last item, simultaneous checkout")
oversold = 0
placed = 0
for i in range(ROUNDS):
    tinker(f'App\\Models\\Product::withoutGlobalScopes()->whereKey("{r["stock"]}")->update(["stock_quantity"=>1,"is_available"=>true]);')
    convs = []
    for k in range(2):
        code, b = api("POST", "/conversations", RO, {"phone": f"+9617{i:03d}{k}{int(time.time())%10000:04d}", "name": f"Race {i}{k}"})
        cid = b["data"]["id"]
        code, b = api("POST", f"/conversations/{cid}/turns", RO, {"message": "add 1 last kunafa"})
        code, b = api("POST", f"/conversations/{cid}/turns", RO, {"message": "add 1 zaatar manousheh"})
        convs.append(cid)
    res = [None, None]
    def go(k):
        res[k] = api("POST", f"/conversations/{convs[k]}/turns", None, {"message": "confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery"},
                     headers={"Authorization": f"Bearer {RO}"})
    th = [threading.Thread(target=go, args=(k,)) for k in range(2)]
    [t.start() for t in th]; [t.join() for t in th]
    st = tj(f'echo json_encode(["stock"=>App\\Models\\Product::withoutGlobalScopes()->find("{r["stock"]}")->stock_quantity,'
            f'"orders"=>App\\Models\\Order::withoutGlobalScopes()->whereIn("conversation_id",["{convs[0]}","{convs[1]}"])->count()]);')
    if i == 0:
        print([ (x[1].get("data") or {}).get("ai", {}).get("reply") for x in res])
    print(f"round {i}: stock={st['stock']} orders={st['orders']} codes={[x[0] for x in res]}")
    placed += st["orders"] >= 1
    if st["orders"] > 1 or st["stock"] < 0:
        oversold += 1
check(f"an order was placed in every race", placed == ROUNDS, f"{placed} of {ROUNDS}")
check(f"last item never sold twice / stock never negative ({ROUNDS} races)", oversold == 0, f"{oversold} of {ROUNDS} races oversold")
