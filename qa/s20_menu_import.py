"""Menu import from files: dollars and lira per row, Arabic headers, re-import updates, broken files."""
import os, uuid, urllib.request
from lib import *

s = state(); W = s["shops"]["whole"]; T = W["token"]


def upload(path, filename, token=T, ctype="text/csv"):
    boundary = "----qa" + uuid.uuid4().hex
    data = open(path, "rb").read()
    body = (f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{filename}\"\r\nContent-Type: {ctype}\r\n\r\n").encode() + data + f"\r\n--{boundary}--\r\n".encode()
    req = urllib.request.Request(API + "/menu/import/preview", data=body, method="POST")
    req.add_header("Content-Type", f"multipart/form-data; boundary={boundary}")
    req.add_header("Accept", "application/json"); req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, json.loads(r.read())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"{}")


def write(name, text, enc="utf-8"):
    p = f"{LAB}/{name}"; open(p, "w", encoding=enc).write(text); return p


def products():
    return tj('echo json_encode(App\\Models\\Product::withoutGlobalScopes()->where("merchant_id","' + W["id"] + '")->with(["category"=>fn($q)=>$q->withoutGlobalScopes()])->get()->map(fn($p)=>[$p->name,$p->price,$p->category?->name])->all());')


suite("Menu import")
api("PATCH", "/settings/profile", T, {"lbp_rate": 89500})
csv = write("menu_mixed.csv",
            "Category,Item,Price,Description\n"
            "Water,Gallon 19L,3$,Spring water\n"
            "Water,Small bottle pack,\"250,000 LL\",12 bottles\n"
            "Gas,Gas cylinder,18,10 kg\n"
            "Gas,Gas refill,1500000,Lira without a sign\n"
            "Water,Gallon 19L (cold),\"3,50\",\n")
code, b = upload(csv, "menu.csv")
items = b.get("items", []) if isinstance(b, dict) else []
print("  preview:", [(i["name"], i["price"], i["lbp"]) for i in items])
check("preview reads all 5 rows", code == 200 and len(items) == 5, (code, b))
by = {i["name"]: i for i in items}
check("'250,000 LL' row marked as lira", by.get("Small bottle pack", {}).get("lbp") is True, by.get("Small bottle pack"))
check("'1500000' without a sign is lira (1,000 and up)", by.get("Gas refill", {}).get("lbp") is True, by.get("Gas refill"))
check("'3$' and '18' stay dollars", by.get("Gallon 19L", {}).get("lbp") is False and by.get("Gas cylinder", {}).get("lbp") is False, (by.get("Gallon 19L"), by.get("Gas cylinder")))
check("'3,50' read as 3.50", abs(float(by.get("Gallon 19L (cold)", {}).get("price", 0)) - 3.5) < 0.001, by.get("Gallon 19L (cold)"))
before = len(products())
code, b = api("POST", "/menu/import", T, {"items": items})
check("import saves the rows (new or updated from an earlier run)", code == 200 and b["result"]["created"] + b["result"]["updated"] == 5, (code, b))
pr = {p[0]: p for p in products()}
check("lira rows converted at 89,500: 250,000 LL → 2.79$", pr.get("Small bottle pack", [0, 0])[1] == "2.79", pr.get("Small bottle pack"))
check("1,500,000 LL → 16.76$", pr.get("Gas refill", [0, 0])[1] == "16.76", pr.get("Gas refill"))
check("categories created from the file", pr.get("Gas cylinder", [0, 0, 0])[2] == "Gas", pr.get("Gas cylinder"))

before2 = len(products())
code, b = api("POST", "/menu/import", T, {"items": [{"name": "Gallon 19L", "price": 3.25, "category": "Water", "lbp": False}]})
pr2 = {p[0]: p for p in products()}
check("importing again updates the price instead of adding a copy", code == 200 and b["result"]["updated"] == 1 and pr2["Gallon 19L"][1] == "3.25" and len(products()) == before2, (b, pr2.get("Gallon 19L")))

n0 = len(products())
code, b = api("POST", "/menu/import", T, {"items": [{"name": "Good item", "price": 4, "category": "Water"}, {"name": "Typo item", "price": 0, "category": "Water"}]})
check("a 0.00 row stops the whole import, naming the row", code == 422 and "Typo item" in json.dumps(b) and len(products()) == n0, (code, str(b)[:200]))
code, b = api("POST", "/menu/import", T, {"items": [{"name": "Tiny lira", "price": 100, "category": "Water", "lbp": True}]})
check("100 LL (0.00$ after converting) is refused", code == 422, (code, str(b)[:200]))

ar = write("menu_ar.csv", "الفئة,الاسم,السعر\nمياه,غالون مياه,٣\nغاز,قارورة غاز,18$\n")
code, b = upload(ar, "قائمة.csv")
print("  Arabic preview:", code, [(i["name"], i["price"]) for i in (b.get("items") or [])] if isinstance(b, dict) else b)
check("Arabic headers and names are read", code == 200 and any(i["name"] == "قارورة غاز" for i in b.get("items", [])), (code, b))

suite("Menu import: broken and unsafe files")
code, b = upload(write("empty.csv", ""), "empty.csv")
check("empty file: clear message, nothing imported", code == 422, (code, b))
code, b = upload(write("noprice.csv", "Item\nGallon\nGas\n"), "noprice.csv")
check("rows without prices: 'no products found'", code == 422, (code, b))
garbage = f"{LAB}/garbage.csv"; open(garbage, "wb").write(os.urandom(4096))
code, b = upload(garbage, "garbage.csv")
check("random bytes named .csv: refused cleanly, no server error", code == 422, (code, str(b)[:120]))
code, b = upload(write("evil.php", "<?php echo 'x';"), "evil.php", ctype="application/x-php")
check("a .php file is refused", code == 422, (code, b))
code, b = upload(write("big.csv", "Item,Price\n" + "".join(f"Item {i},{i % 50 + 1}\n" for i in range(1500))), "big.csv")
check("a 1,500-row file is cut to 1,000 rows, no error", code == 200 and len(b.get("items", [])) <= 1000, (code, len(b.get("items", [])) if isinstance(b, dict) else b))
code, b = api("POST", "/menu/import", T, {"items": [{"name": "x" * 191, "price": 1}]})
check("a 191-character name is refused", code == 422, code)
code, b = api("POST", "/menu/import", T, {"items": [{"name": "Formula", "price": 1, "category": "=HYPERLINK(\"http://evil\")"}]})
print("  formula-looking category:", code)

suite("Menu import: access")
# Staff cannot sign in to the dashboard, so mint their token the way the lab set them up (see s03).
STAFF_TOKEN = tj('$u=App\\Models\\User::where("email","' + s["rest_staff"]["email"] + '")->first(); echo json_encode($u->createToken("qa-s20")->plainTextToken);')
code, b = upload(csv, "menu.csv", token=STAFF_TOKEN)
check("shop staff cannot import", code == 403, code)
code, b = api("POST", "/menu/import", s["shops"]["rest"]["token"], {"items": [{"name": "Gallon 19L", "price": 99, "category": "Water"}]})
pr3 = {p[0]: p for p in products()}
check("another shop's import never touches this shop's products", pr3["Gallon 19L"][1] == "3.25", pr3.get("Gallon 19L"))
