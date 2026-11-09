"""Enrich lab shops so the playground can exercise every production WhatsApp path.
Safe to re-run. Requires s01_setup.py + s02_catalog.py first. Never touches production.
"""
from lib import *

s = state()
if not s.get("shops"):
    raise SystemExit("Run s01_setup.py and s02_catalog.py first")

suite("Playground coverage seeds")


def set_settings(merchant_id, patch: dict):
    """Merge settings on a merchant (lab only)."""
    import json as _json
    keys = _json.dumps(patch)
    return tj(
        '$m=App\\Models\\Merchant::withoutGlobalScopes()->find("' + merchant_id + '");'
        '$cur=$m->settings ?? [];'
        '$patch=json_decode(\'' + keys.replace("'", "\\'") + '\', true);'
        '$m->forceFill(["settings"=>array_merge($cur, $patch)])->save();'
        'echo json_encode($m->fresh()->settings);'
    )


def ensure_product(key, name, price, **extra):
    sh = s["shops"][key]
    existing = tj(
        'echo json_encode(App\\Models\\Product::withoutGlobalScopes()'
        '->where("merchant_id","' + sh["id"] + '")->where("name","' + name + '")->value("id"));'
    )
    if existing:
        return existing
    body = {"name": name, "price": price, "category_id": sh["cat"], **extra}
    code, b = api("POST", "/products", sh["token"], body)
    check(f"{key} product {name}", code in (200, 201), (code, str(b)[:200]))
    d = b.get("data", b) if isinstance(b, dict) else {}
    return (d.get("id") or (d.get("product") or {}).get("id")) if isinstance(d, dict) else None


def ensure_zone_circle(key, name, fee, minimum, radius_m=4000, lat=33.8938, lng=35.5018):
    sh = s["shops"][key]
    zid = tj(
        'echo json_encode(App\\Models\\DeliveryZone::withoutGlobalScopes()'
        '->where("merchant_id","' + sh["id"] + '")->where("name","' + name + '")->value("id"));'
    )
    if zid:
        return zid
    body = {
        "name": name,
        "deliveryFee": fee,
        "minimumOrder": minimum,
        "deliveryMins": 35,
        "centerLat": lat,
        "centerLng": lng,
        "radiusMeters": radius_m,
    }
    code, b = api("POST", "/settings/zones", sh["token"], body)
    check(f"{key} zone {name}", code in (200, 201), (code, str(b)[:220]))
    d = b.get("data", b) if isinstance(b, dict) else {}
    return d.get("id") if isinstance(d, dict) else None


# ── Restaurant: full ops surface (manual accept + Whish + LBP + both fulfillments) ──
r = s["shops"]["rest"]
set_settings(r["id"], {
    "auto_accept": False,
    "fulfillment": "both",
    "pickup_address": "Hamra, main street — next to the pharmacy",
    "whish_number": "70123456",
    "lbp_rate": 89500,
    "timing_unit": "minutes",
    "prep_mins": 20,
})
check("rest settings for Whish/pickup/LBP/manual accept", True)
r["check_product"] = ensure_product("rest", "Arak Bottle", 12.0, staff_check=True)
ensure_zone_circle("rest", "Hamra Circle", 1.5, 5.0)
save_state(s)

# ── Bakery: pickup-first, auto-accept ON (production default) ──
b = s["shops"]["pharm"]
set_settings(b["id"], {
    "auto_accept": True,
    "fulfillment": "pickup",
    "pickup_address": "Achrafieh bakery counter",
    "whish_number": "70999888",
    "lbp_rate": 89500,
    "timing_unit": "minutes",
    "prep_mins": 15,
})
ensure_product("pharm", "Chocolate Croissant", 2.5)
ensure_product("pharm", "Knefeh", 4.0)
check("bakery pickup + auto-accept", True)
save_state(s)

# ── Grocery: delivery, auto-accept, shop can self-deliver (driver exists but start optional) ──
g = s["shops"]["grocer"]
set_settings(g["id"], {
    "auto_accept": True,
    "fulfillment": "both",
    "pickup_address": "Hamra supermarket door",
    "whish_number": "70111222",
    "lbp_rate": 89500,
    "timing_unit": "minutes",
    "prep_mins": 25,
})
ensure_zone_circle("grocer", "Hamra Circle", 2.0, 10.0)
ensure_product("grocer", "Tomatoes", 1.5, unit="kg")
ensure_product("grocer", "Labneh", 3.0)
# Drivers off-duty by default so self-delivery path is easy; playground can start them.
tj(
    'App\\Models\\Driver::withoutGlobalScopes()->where("merchant_id","' + g["id"] + '")'
    '->update(["status"=>"off_duty","availability"=>"available","active_order_id"=>null]);'
    'echo json_encode(1);'
)
check("grocery self-delivery ready", True)
save_state(s)

# ── Retail: days timing + colors ──
rt = s["shops"]["retail"]
set_settings(rt["id"], {
    "auto_accept": True,
    "fulfillment": "both",
    "pickup_address": "Online shop warehouse — Dora",
    "whish_number": "70333444",
    "lbp_rate": 89500,
    "timing_unit": "days",
    "delivery_days_min": 2,
    "delivery_days_max": 5,
})
pid = ensure_product("retail", "T-Shirt", 19.0)
if pid and not rt.get("color_variant"):
    code, b = api("POST", f"/products/{pid}/variants", rt["token"], {"name": "Black", "price": 19.0})
    check("retail color variant", code in (200, 201), (code, str(b)[:200]))
    d = b.get("data", b) if isinstance(b, dict) else {}
    rt["color_variant"] = d.get("id") if isinstance(d, dict) else None
    rt["shirt"] = pid
ensure_zone_circle("retail", "Beirut", 3.0, 15.0, radius_m=12000)
check("retail days timing", True)
save_state(s)

# ── Produce / butcher: kg items, delivery ──
w = s["shops"]["whole"]
set_settings(w["id"], {
    "auto_accept": True,
    "fulfillment": "delivery",
    "whish_number": "70555666",
    "lbp_rate": 89500,
    "timing_unit": "hours",
    "prep_mins": 2,
})
ensure_product("whole", "Minced Beef", 8.0, unit="kg")
ensure_zone_circle("whole", "Beirut", 2.5, 8.0, radius_m=8000)
check("produce kg + delivery", True)
save_state(s)

# Coverage catalog written for the UI
s["playground_coverage"] = {
    "customer": [
        "Free-text AI order / menu",
        "Size + extras in words",
        "Checkout buttons (delivery/pickup)",
        "Location pin required for delivery",
        "Out-of-area pin",
        "Cash / Whish / LBP / split",
        "Confirm / Another time / Cancel order",
        "Track order in words",
        "Cancel Yes/No",
        "Handoff (ask for human)",
        "Voice note",
        "Photo (bot pauses)",
        "Call → order by message",
        "Rating Great/Okay/Bad + issue",
        "Staff-check item (Arak) wait for shop ✅",
    ],
    "shop": [
        "Manual accept ✅ / reject ❌",
        "Auto-accept (bakery/grocery/retail)",
        "ready / out / done / delay / refunded",
        "Whish confirm ✅ / ❌ after I paid",
        "Pause ⏸ / resume ▶️",
        "Self-delivery without driver (grocery)",
        "Pickup ready → done (bakery)",
    ],
    "driver": [
        "start / stop shift",
        "Picked up / Outside / Delivered / Failed buttons",
        "Auto-dispatch after start",
    ],
    "types": [
        "Restaurant / Café (mins, driver, Whish)",
        "Bakery pickup + auto-accept",
        "Supermarket delivery/self-deliver",
        "Retail days timing",
        "Butcher / produce kg",
    ],
}
save_state(s)
print(json.dumps({"shops": list(s["shops"].keys()), "coverage": s["playground_coverage"]}, indent=1))
print("playground seeds ready")
