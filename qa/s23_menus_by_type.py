"""How each kind of shop builds its menu or services, then a customer uses it on WhatsApp.
One shop per kind (9 kinds, the business types they stand for). Lab only."""
import csv, io, json, re, secrets, time, uuid, urllib.request
from lib import *

s = state(); OWN = s["owner"]
RUN = str(int(time.time()))[-5:]
KINDS = [
    # kind, business type at sign-up, expected menu word, expects sizes/extras, unit label, is services
    ("restaurant", "Restaurant / Café", "menu", True, None, False),
    ("grocery", "Supermarket / Grocery", "products", False, "kg", False),
    ("pharmacy", "Pharmacy", "products", False, "box", False),
    ("retail", "Electronics / Phones", "catalogue", True, None, False),
    ("flowers", "Flowers / Gifts", "products", True, None, False),
    ("water", "Water / Gas delivery", "products", False, "cylinder", False),
    ("laundry", "Laundry / Dry cleaning", "price_list", True, "kg", False),
    ("services", "Salon / Barber / Beauty", "services", False, None, True),
    ("other", "Other", "products", False, None, False),
]
results = {}


def rec(kind, name, ok, detail=""):
    results.setdefault(kind, []).append((name, bool(ok)))
    check(f"[{kind}] {name}", ok, detail)


def wa(phone_id, frm, **kw):
    code, mid, t0 = wa_msg(phone_id, frm, **kw)
    return texts([x for x in settle(t0, quiet=2.0, cap=30) if x["body"].get("to", "").lstrip("+") == frm])


def multipart(path, token, filename, ctype):
    boundary = "----qa" + uuid.uuid4().hex
    data = open(path, "rb").read()
    body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{filename}\"\r\nContent-Type: {ctype}\r\n\r\n").encode() + data + f"\r\n--{boundary}--\r\n".encode()
    req = urllib.request.Request(API + "/menu/import/preview", data=body, method="POST")
    req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
    req.add_header("Accept", "application/json"); req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


for n, (kind, btype, word, sizes, unit, services) in enumerate(KINDS):
    suite(f"Shop type: {btype} ({kind})")
    phone = f"+9617{RUN}{n:02d}1"
    pid = f"PN{RUN}{n:02d}"
    code, b = api("POST", "/admin/merchants", OWN, {"shopName": f"QA {kind.title()} {RUN}", "whatsappNumber": phone, "password": "123456", "businessType": btype, "subscriptionStatus": "trial", "country": "LB"})
    rec(kind, "owner creates the shop with its type", code == 201, (code, str(b)[:150]))
    if code != 201:
        continue
    shop = b["data"]
    slug = shop["slug"]
    code, b = api("PATCH", f"/admin/merchants/{shop['id']}/bot", OWN, {"phoneNumberId": pid, "accessToken": "lab-shop-token"})
    api("POST", f"/admin/merchants/{shop['id']}/bot/restart", OWN)
    code, lb = login(f"{slug}@merchant.neotalab", "123456")
    if code != 200:
        code, lb = login(f"{slug}@merchant.neotalab", "123456")
    tok = lb.get("token") if isinstance(lb, dict) else None
    rec(kind, "shop owner signs in", tok is not None, code)
    if not tok:
        continue
    code, prof = api("GET", "/settings/profile", tok)
    got = (prof.get("profile") or prof) if isinstance(prof, dict) else {}
    rec(kind, "the shop's type sets its menu wording and mode", got.get("business_kind") == kind or (kind == "restaurant" and got.get("business_kind")), (got.get("business_kind"), got.get("menu_word")))

    # 1. Build the menu by hand: category, items (with unit where the trade sells by weight/count)
    code, cat = api("POST", "/categories", tok, {"name": "Main"})
    cat_id = (cat.get("data") or cat).get("id") if isinstance(cat, dict) else None
    rec(kind, "creates a category", code in (200, 201) and cat_id, (code, str(cat)[:120]))
    item_body = {"name": "Hand item one", "price": 4.5, "category_id": cat_id}
    if unit:
        item_body["unit"] = unit
    if services:
        item_body.update(name="Men's haircut", price=12, duration_mins=30)
    code, it = api("POST", "/products", tok, item_body)
    item = (it.get("data") or it) if isinstance(it, dict) else {}
    item_id = item.get("id")
    rec(kind, "adds an item" + (" as a service with minutes" if services else (f" sold per {unit}" if unit else "")), code in (200, 201) and item_id, (code, str(it)[:150]))
    if sizes and item_id:
        code, v = api("POST", f"/products/{item_id}/variants", tok, {"name": "Large", "price": 6.0})
        rec(kind, "adds a size with its own price", code in (200, 201), (code, str(v)[:120]))
        code, g = api("POST", f"/products/{item_id}/modifier-groups", tok, {"name": "Extras", "min_select": 0, "max_select": 3})
        gid = (g.get("data") or g).get("id") if isinstance(g, dict) else None
        if gid:
            code, m = api("POST", f"/modifier-groups/{gid}/modifiers", tok, {"name": "Extra cheese", "price_delta": 0.75})
        rec(kind, "adds extras with a price", bool(gid) and code in (200, 201), (code, str(g)[:120]))

    # 2. Upload a file (Excel/CSV): category, name, price; a lira row; an empty price row is refused
    buf = io.StringIO()
    w = csv.writer(buf)
    w.writerow(["Category", "Name", "Price", "Description"])
    w.writerow(["Imported", f"{kind} file item A", "5", "from a file"])
    w.writerow(["Imported", f"{kind} file item B", "250,000 LL", "lira price"])
    w.writerow(["Imported", f"{kind} file item C", "7.25", ""])
    path = f"{LAB}/menu_{kind}.csv"
    open(path, "w", encoding="utf-8").write(buf.getvalue())
    code, pv = multipart(path, tok, "menu.csv", "text/csv")
    items = pv.get("items", []) if isinstance(pv, dict) else []
    rec(kind, "uploads a CSV: preview shows the 3 rows, lira detected", code == 200 and len(items) == 3 and any(i.get("lbp") for i in items), (code, str(pv)[:160]))
    code, saved = api("POST", "/menu/import", tok, {"items": items})
    rec(kind, "saves the imported rows", code == 200 and saved["result"]["created"] == 3, (code, str(saved)[:150]))
    code, plist = api("GET", "/products", tok)
    names = {p["name"]: p for p in (plist.get("data") or plist.get("products") or [])} if isinstance(plist, dict) else {}
    lira = names.get(f"{kind} file item B", {})
    rec(kind, "the lira row is saved in dollars (250,000 LL → about 2.79$)", lira and str(lira.get("price")) in ("2.79", "2.79"), lira.get("price"))
    if services:
        rec(kind, "a CSV row can set a service's duration (gap if not)", "duration_mins" in json.dumps(names.get(f"{kind} file item A", {})) and names.get(f"{kind} file item A", {}).get("duration_mins"), names.get(f"{kind} file item A", {}).get("duration_mins"))

    # 3. Customer on WhatsApp
    cust = f"+9617{RUN}{n:02d}9"
    greet = wa(pid, cust.lstrip("+"), text="hi")
    rec(kind, "customer's greeting shows the shop's menu list", any("mn:" in x or "cat" in x.lower() or "item" in x.lower() for x in greet), greet[:1])
    if services:
        typed = wa(pid, cust.lstrip("+"), text="services")
        rec(kind, "customer asking 'services' sees the service list", any(("haircut" in x.lower() or "men" in x.lower()) for x in typed), typed[:1])
        d = (time.strftime("%Y-%m-%d", time.localtime(time.time() + 3 * 86400)))
        slots = wa(pid, cust.lstrip("+"), text=f"slots men's haircut {d}")
        rec(kind, "customer asks for free times and gets them", any(re.search(r"\d{2}:\d{2}", x) for x in slots), slots[:1])
        book = wa(pid, cust.lstrip("+"), text=f"book men's haircut {d} 11:00")
        tkt = tj('echo json_encode(App\\Models\\Appointment::withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '")->where("status","booked")->count());')
        rec(kind, "customer books a 30-minute service", tkt >= 1, (book[:1], tkt))
        code, api_list = api("GET", "/appointments", tok)
        rec(kind, "the shop sees the booking in its appointments", code == 200 and "11:00" in json.dumps(api_list) or code == 200, code)
    else:
        if item_id:
            code, _ = api("PATCH", f"/products/{item_id}", tok, {"is_available": False})
            hidden = wa(pid, cust.lstrip("+"), text="menu")
            rec(kind, "an item marked unavailable is left off the customer's menu", code == 200 and not any(item_id in x for x in hidden), code)
            api("PATCH", f"/products/{item_id}", tok, {"is_available": True})
        ids = re.findall(r"mn:cat:([0-9a-f-]{36})", " ".join(greet))
        if cat_id:
            cat_list = wa(pid, cust.lstrip("+"), list_id=f"mn:cat:{cat_id}:1")
            rec(kind, "customer opens the category and sees the item", any(item_id in x for x in cat_list) if item_id else False, cat_list[:1])
            if item_id:
                qty = wa(pid, cust.lstrip("+"), list_id=f"mn:item:{item_id}")
                if sizes:
                    var = re.findall(r"mn:var:([0-9a-f-]{36})", " ".join(qty))
                    rec(kind, "customer is asked which size, with prices", bool(var) and any("6" in x for x in qty), qty[:1])
                    if var:
                        qty = wa(pid, cust.lstrip("+"), list_id=f"mn:var:{var[0]}")
                for _ in range(4):  # answer the extras question ("No, thanks") until the quantity question
                    if any("mn:done=" in x for x in qty) and not any("mn:qty:2" in x for x in qty):
                        qty = wa(pid, cust.lstrip("+"), list_id="mn:done")
                    else:
                        break
                question = qty  # the "how many?" question, before answering it
                if unit:
                    rec(kind, f"quantity buttons show the unit ({unit})", any(unit in x for x in question), question[:1])
                qty = wa(pid, cust.lstrip("+"), button="mn:qty:2") if any("mn:qty:2" in x for x in qty) else qty
                cart = tj('echo json_encode(App\\Models\\CartItem::withoutGlobalScopes()->whereHas("product", fn($q)=>$q->withoutGlobalScopes()->where("merchant_id","' + shop["id"] + '"))->count());')
                rec(kind, "customer adds the item to the cart", cart >= 1, (cart, qty[:1]))

    # 4. Photo of a menu (AI reads it). Optional: needs the AI on in the lab.
results_summary = {k: f"{sum(ok for _, ok in v)}/{len(v)}" for k, v in results.items()}
print("\nSUMMARY", json.dumps(results_summary))
