"""Voice notes (real speech-to-text when the queue runs with lab-ai.sh) and WhatsApp calls.
Recordings live in <lab>/media/VOICE_<lang>.ogg (made with macOS `say` + ffmpeg, OGG/Opus like WhatsApp)."""
from lib import *

s = state(); R = s["shops"]["rest"]; PID = R["phone_id"]
RUN = str(int(time.time()))[-5:]
n = [0]


def fresh():
    n[0] += 1
    return f"9617930{RUN}{n[0]:02d}"


def voice(frm, media_id):
    code, mid, t0 = wa_msg(PID, frm, kind={"type": "audio", "audio": {"id": media_id, "mime_type": "audio/ogg; codecs=opus", "voice": True}})
    return " || ".join(texts([x for x in wait_reply(t0, frm) if x["body"].get("to", "").lstrip("+") == frm]))


def zaatar(phone):
    return tj('$c=App\\Models\\Customer::withoutGlobalScopes()->where("merchant_id","' + R["id"] + '")->where("phone","+' + phone + '")->first();'
              '$cv=$c?App\\Models\\Conversation::withoutGlobalScopes()->where("customer_id",$c->id)->latest()->first():null;'
              '$ct=$cv?App\\Models\\Cart::withoutGlobalScopes()->where("conversation_id",$cv->id)->where("status","open")->first():null;'
              'echo json_encode($ct?(int)App\\Models\\CartItem::withoutGlobalScopes()->where("cart_id",$ct->id)->whereHas("product", fn($q)=>$q->withoutGlobalScopes()->where("name","Zaatar Manousheh"))->sum("quantity"):0);')


def transcript(phone):
    return tj('echo json_encode(App\\Models\\WhatsAppMessage::withoutGlobalScopes()->where("from_number","+' + phone + '")->latest("id")->first()?->payload["transcript"] ?? null);')


suite("Voice notes (real speech-to-text)")
for lang, want, script in (("en", 2, None), ("ar", 2, "ar"), ("fr", 3, "fr")):
    c = fresh()
    out = voice(c, f"VOICE_{lang}")
    print(f"  {lang}: heard {transcript(c)!r} → {out[:160]}")
    check(f"{lang} voice note understood: {want} zaatar in the cart", zaatar(c) == want, (transcript(c), out))
c = fresh()
out = voice(c, "VOICE_bad")
check("unreadable voice note: polite note + menu, never silence", "mn:" in out and out != "", out)
c = fresh()
out = voice(c, "VOICE_missing")
check("voice note Meta can't serve: polite note + menu", "mn:" in out, out)

suite("WhatsApp calls")
c = fresh()
# 1. Calling API event (only sent when calling is switched on for the number).
payload = {"object": "whatsapp_business_account", "entry": [{"id": "WABA", "changes": [{"field": "calls", "value": {
    "messaging_product": "whatsapp", "metadata": {"display_phone_number": "961", "phone_number_id": PID},
    "calls": [{"id": "wacid.QA" + RUN, "from": c, "to": "961", "event": "connect", "timestamp": str(int(time.time())), "direction": "USER_INITIATED"}]}}]}]}
t0 = time.time()
code, _ = wa_post(payload)
out = texts([x for x in wait_reply(t0, c, cap=20) if x["body"].get("to", "").lstrip("+") == c])
check("call event accepted without error", code == 200, code)
check("a customer who calls gets 'order here instead' + the menu, not silence", any("mn:" in x for x in out), out)
t1 = time.time(); wa_post(payload); settle(t1, quiet=2.0)
check("calling again within 10 minutes: no second message", len(outbox(to=c, since=t1)) == 0)
# 2. Missed-call style message Meta may deliver as an unsupported message type.
code, mid, t0 = wa_msg(PID, c, kind={"type": "unsupported", "errors": [{"code": 131051, "title": "Message type unknown", "message": "Message type is not currently supported"}]})
out = texts([x for x in wait_reply(t0, c) if x["body"].get("to", "").lstrip("+") == c])
print("  reply to an unsupported (call) message:", out)
check("unsupported message type (e.g. a call log): polite note + menu", any("mn:" in x for x in out), out)
failed = tj('echo json_encode(DB::table("failed_jobs")->where("failed_at",">=",now()->subMinutes(5))->count());')
check("no failed background jobs", failed == 0, failed)
