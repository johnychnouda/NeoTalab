"""Scale lab, REAL assistant (paid OpenAI calls, model from OPENAI_MODEL): a customer orders in free text and types the
delivery address in the same breath, the way people really write. Delivery needs the location pin, so no order may be
placed from typed words alone: the customer must be asked for the pin, and once it is sent the order carries the pin and
the typed words as the driver note. EN, Lebanese Arabic, Arabizi and French. Workers run with AI_DRIVER=openai.
usage: ./scale_env.sh python3 -u s51_real_ai_typed_address.py   (needs OPENAI_API_KEY in backend/.env)"""
import os, re, subprocess
from lib import *

assert "neotalab_qa_scale" in ENV["DB_DATABASE"], "refusing: not the scale lab"
s = state(); r = s["scale"]["sc000"]; PID, MID = r["pid"], r["id"]
RUN = str(int(time.time()))[-5:]
KEY = next(l.split("=", 1)[1].strip() for l in open(os.path.join(QA, "..", "backend", ".env")) if l.startswith("OPENAI_API_KEY="))
MODEL = os.environ.get("OPENAI_MODEL", "gpt-6-luna")
SH = f'set -a; source "{QA}/.lab/scale.env"; set +a; export AI_DRIVER=openai OPENAI_API_KEY="{KEY}" OPENAI_MODEL={MODEL}; cd "{QA}/../backend"; exec php artisan queue:work --sleep=1 --tries=3 --timeout=75'
workers = [subprocess.Popen(["bash", "-c", SH], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True) for _ in range(3)]
item = tj(f'echo json_encode(App\\Models\\Product::withoutGlobalScopes()->where("merchant_id","{MID}")->where("is_active",true)->where("is_available",true)->whereNull("duration_mins")->orderBy("sort_order")->value("name"));')
ZONES = tj(f'echo json_encode(App\\Models\\DeliveryZone::withoutGlobalScopes()->where("merchant_id","{MID}")->where("is_active",true)->pluck("id")->all());')
CASES = {
    "en": f"Hi, I want 2 {item} delivered to Hamra, Bliss Street, Nour building 3rd floor. Cash.",
    "ar": f"مرحبا بدي ٢ {item} توصيل على الحمرا شارع بلس بناية النور الطابق التالت، كاش",
    "arabizi": f"marhaba baddi 2 {item} delivery 3al hamra share3 bliss binayet el nour tabe2 3, cash",
    "fr": f"Bonjour, je voudrais 2 {item} livrés à Hamra, rue Bliss, immeuble Nour 3e étage. Espèces.",
}


def say(frm, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    allout = settle(t0, quiet=4, cap=60)
    mine = [x for x in allout if x["body"].get("to", "").lstrip("+") == frm]
    return texts(mine), mine


def orders(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + MID + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->get(["id","delivery_lat","delivery_address","status"]));')


def asked_pin(raw):
    return any(x["body"].get("interactive", {}).get("type") == "location_request_message" for x in raw)


try:
    tj(f'App\\Models\\DeliveryZone::withoutGlobalScopes()->where("merchant_id","{MID}")->update(["is_active"=>false]); echo 1;')
    for i, (lang, text) in enumerate(CASES.items()):
        suite(f"Real assistant ({MODEL}), {lang}: order and address typed in one message")
        C = f"9617613{RUN}{i}"
        say(C, text={"en": "hi", "ar": "مرحبا", "arabizi": "marhaba", "fr": "bonjour"}[lang], name=f"QA Typed {lang}")
        out, raw = say(C, text=text)
        print(f"   [{lang}] ->", [o[:160] for o in out])
        seen_pin = asked_pin(raw)
        # The assistant may first confirm the cart or ask a question: follow its buttons (never a confirm) up to 4 turns.
        for _ in range(4):
            if seen_pin:
                break
            ids = [p.split("=")[0] for x in out for g in re.findall(r"\[(?:BTN|LIST) ([^\]]+)\]", x) for p in re.split(r" \| |,", g)]
            pick = next((b for b in ids if b.startswith(("co:start", "co:ful:delivery", "co:cur:USD", "co:pay:cash"))), None)
            out, raw = say(C, button=pick) if pick else say(C, text={"en": "checkout please", "ar": "بدي اطلب", "arabizi": "baddi otlob", "fr": "je veux commander"}[lang])
            print(f"   [{lang}] ->", [o[:160] for o in out])
            seen_pin = asked_pin(raw)
        check(f"{lang}: no order was placed from the typed address alone", not orders(C), orders(C))
        check(f"{lang}: the customer is asked for the location pin", seen_pin, out)
        if not seen_pin:
            continue
        out, raw = say(C, location=(33.8966, 35.4823))
        for _ in range(5):
            ids = [p.split("=")[0] for x in out for g in re.findall(r"\[(?:BTN|LIST) ([^\]]+)\]", x) for p in re.split(r" \| |,", g)]
            if "co:ok:" in ids:
                break
            pick = next((b for b in ids if b.startswith(("co:cur:USD", "co:pay:cash", "co:when:asap", "co:skip:"))), None)
            out, raw = say(C, button=pick) if pick else say(C, text="Nour building, 3rd floor")
        out, raw = say(C, button="co:ok:")
        o = orders(C)
        check(f"{lang}: after the pin, one order with the pin", len(o) == 1 and bool(o[0]["delivery_lat"]), o)
        print(f"   [{lang}] order address:", o[0]["delivery_address"] if o else None)
    usage = tj(f'echo json_encode(DB::table("ai_usage")->where("created_at",">=",now()->subMinutes(30))->selectRaw("count(*) n, sum(input_tokens) i, sum(output_tokens) o")->first());') if tj('echo json_encode(Schema::hasTable("ai_usage"));') else None
    print("   AI usage in the last 30 min:", usage)
finally:
    for w in workers:
        w.kill()
    tj('App\\Models\\DeliveryZone::withoutGlobalScopes()->whereIn("id",' + json.dumps(ZONES) + ')->update(["is_active"=>true]); echo 1;')
