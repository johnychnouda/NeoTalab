"""The shop phone answering from the WhatsApp Business app in a customer's chat (coexistence echoes)."""
import uuid
from lib import *

s = state(); R = s["shops"]["rest"]; PID = R["phone_id"]; STAFF = "96171222002"
RUN = str(int(time.time()))[-5:]


def say(frm, **kw):
    code, mid, t0 = wa_msg(PID, frm, **kw)
    return texts([x for x in settle(t0) if x["body"].get("to", "").lstrip("+") == frm])


def echo(to, text, echo_id=None):
    payload = {"object": "whatsapp_business_account", "entry": [{"id": "WABA", "changes": [{"field": "smb_message_echoes", "value": {
        "messaging_product": "whatsapp", "metadata": {"display_phone_number": "961", "phone_number_id": PID},
        "message_echoes": [{"from": "961", "to": to, "id": echo_id or "wamid.ECHO" + uuid.uuid4().hex, "timestamp": str(int(time.time())), "type": "text", "text": {"body": text}}]}}]}]}
    t0 = time.time(); wa_post(payload); return settle(t0)


def latest(phone):
    return tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + R["id"] + '")->whereHas("customer", fn($q)=>$q->withoutGlobalScopes()->where("phone","+' + phone + '"))->latest("number")->first()?->only("id","number","status","accepted_at"));')


def place(phone):
    say(phone, text="add 1 zaatar manousheh")
    say(phone, text="confirm | Hamra | Bliss street, building 12 | cash | USD | | delivery")
    return latest(phone)


suite("Shop phone: ✅ ❌ ⏸ ▶️ in a customer's chat")
say(STAFF, text="hi")  # a reachable staff member means orders wait for the shop
c1, c2, c3 = "9617971" + RUN, "9617972" + RUN, "9617973" + RUN
o = place(c1)
check("order waits for the shop", o and o["status"] == "placed", o)
echo(c1, "✅")
o = latest(c1)
check("shop phone ✅ accepts it", o["status"] == "confirmed" and o["accepted_at"], o)
echo(c1, "❌")
check("a later ❌ does not undo an accepted order", latest(c1)["status"] == "confirmed", latest(c1))

o = place(c2)
echo(c2, "❌")
check("shop phone ❌ refuses a waiting order", latest(c2)["status"] == "rejected", latest(c2))

echo(c3, "✅")
check("✅ with no waiting order changes nothing (no crash)", latest(c3) is None)
o = place(c3)
echo(c3, "great, thanks ✅")
check("✅ inside a sentence is just chat, not an accept", latest(c3)["status"] == "placed", latest(c3))
eid = "wamid.ECHO" + uuid.uuid4().hex
echo(c3, "✅", eid)
echo(c3, "✅", eid)  # Meta retries the same echo
check("✅ accepts once even when Meta delivers it twice", latest(c3)["status"] == "confirmed", latest(c3))

c4 = "9617974" + RUN
say(c4, text="hi")
echo(c4, "⏸")
out = say(c4, text="add 1 zaatar manousheh")
check("⏸ pauses the bot: the customer gets only 'the team is answering'", all("mn:" not in x for x in out) and any("team" in x.lower() for x in out), out)
out = say(c4, text="hello?")
check("…and that note is sent at most once in 10 minutes", out == [], out)
echo(c4, "▶️")
out = say(c4, text="hi")
check("▶️ hands the chat back to the bot", any("mn:" in x for x in out), out)
