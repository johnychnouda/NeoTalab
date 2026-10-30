"""Workflow 7: webhook authenticity, malformed payloads, unknown and suspended numbers. The Whish callback was removed with the Whish API (2026-10-29): the old address must now be gone."""
from lib import *

s = state(); r = s["shops"]["rest"]; PID = r["phone_id"]
RUN = str(int(time.time()))[-5:]

suite("W7 webhook verification (GET)")
code, b = api("GET", "/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=lab-verify&hub.challenge=12345")
check("correct verify token echoes the challenge", code == 200 and str(b) == "12345", (code, b))
code, b = api("GET", "/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345")
check("wrong verify token refused", code == 403, code)
code, b = api("GET", "/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=12345")
check("missing verify token refused", code == 403, code)

suite("W7 webhook signature (POST)")
payload = {"object": "whatsapp_business_account", "entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID},
           "messages": [{"from": "96176300" + RUN, "id": "wamid.SIG" + RUN, "type": "text", "text": {"body": "hi"}}]}}]}]}
t0 = time.time()
code, _ = wa_post(payload, sign=False, sig="")
check("no signature -> 403", code == 403, code)
code, _ = wa_post(payload, sig="sha256=" + "0" * 64)
check("wrong signature -> 403", code == 403, code)
code, _ = wa_post(payload, sig="sha1=abc")
check("malformed signature -> 403", code == 403, code)
settle(t0, quiet=1.0)
check("unsigned messages get no reply", len(outbox(to="96176300" + RUN, since=t0)) == 0)
nrec = tj('echo json_encode(App\\Models\\WhatsAppMessage::withoutGlobalScopes()->where("wa_message_id","wamid.SIG' + RUN + '")->count());')
check("unsigned messages are not stored", nrec == 0, nrec)
# Body changed after signing
raw = json.dumps(payload).encode()
import hmac, hashlib
sig = "sha256=" + hmac.new(ENV["WHATSAPP_APP_SECRET"].encode(), raw, hashlib.sha256).hexdigest()
tampered = raw.replace(b'"hi"', b'"add 9 falafel"')
code, _ = api("POST", "/webhooks/whatsapp", raw=tampered, headers={"X-Hub-Signature-256": sig})
check("body changed after signing -> 403", code == 403, code)

suite("W7 malformed and odd payloads (signed)")
odd = [
    ("empty object", {}),
    ("entry is a string", {"entry": "x"}),
    ("changes not a list", {"entry": [{"changes": {"a": 1}}]}),
    ("value missing", {"entry": [{"changes": [{}]}]}),
    ("messages is a string", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": "hi"}}]}]}),
    ("message without id", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": [{"from": "961763", "type": "text", "text": {"body": "x"}}]}}]}]}),
    ("message without from", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": [{"id": "wamid.NF" + RUN, "type": "text", "text": {"body": "x"}}]}}]}]}),
    ("text body is a number", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": [{"id": "wamid.NUM" + RUN, "from": "96176301" + RUN, "type": "text", "text": {"body": 123}}]}}]}]}),
    ("interactive without reply", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": [{"id": "wamid.INT" + RUN, "from": "96176302" + RUN, "type": "interactive", "interactive": {}}]}}]}]}),
    ("location without coordinates", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": [{"id": "wamid.LOC" + RUN, "from": "96176303" + RUN, "type": "location", "location": {}}]}}]}]}),
    ("status update only", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "statuses": [{"id": "wamid.X", "status": "read"}]}}]}]}),
    ("unknown phone number id", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": "PNID-NOPE"}, "messages": [{"id": "wamid.UNK" + RUN, "from": "96176304" + RUN, "type": "text", "text": {"body": "hi"}}]}}]}]}),
    ("10,000-char message", {"entry": [{"changes": [{"value": {"metadata": {"phone_number_id": PID}, "messages": [{"id": "wamid.LONG" + RUN, "from": "96176305" + RUN, "type": "text", "text": {"body": "مرحبا 😀 " * 1250}}]}}]}]}),
]
t0 = time.time()
for name, p in odd:
    code, b = wa_post(p)
    check(f"{name}: accepted quickly, no server error", code == 200, (code, str(b)[:100]))
code, b = api("POST", "/webhooks/whatsapp", raw=b"not json{", headers={"X-Hub-Signature-256": "sha256=" + hmac.new(ENV["WHATSAPP_APP_SECRET"].encode(), b"not json{", hashlib.sha256).hexdigest()})
check("signed non-JSON body: no server error", code in (200, 400, 422), code)
settle(t0, quiet=2.0, cap=15)
failed = tj('echo json_encode(DB::table("failed_jobs")->where("failed_at",">=",now()->subMinutes(3))->count());')
check("no failed background jobs from odd payloads", failed == 0, failed)
check("number-body message still answered", len(outbox(to="96176301" + RUN, since=t0)) >= 1, "no reply")
check("10,000-char Arabic/emoji message answered", len(outbox(to="96176305" + RUN, since=t0)) >= 1, "no reply")
check("unknown shop number gets no reply", len(outbox(to="96176304" + RUN, since=t0)) == 0)

suite("W7 suspended shop's number is silent, reactivation restores it")
ph = s["shops"]["retail"]
code, b = api("PATCH", f"/admin/merchants/{ph['id']}", s["owner"], {"status": "suspended"})
check("owner suspends the phone shop", code == 200, code)
cust = "96176310" + RUN
code, mid, t0 = wa_msg(ph["phone_id"], cust, text="hi")
settle(t0, quiet=1.0)
check("suspended shop: customer gets no reply (no cost)", len(outbox(to=cust, since=t0)) == 0)
code, b = login(ph["email"], ph["pw"])
print("  suspended shop owner login:", code, (b or {}).get("merchant", {}).get("status") if isinstance(b, dict) else b)
code, b = api("GET", "/settings/setup", ph["token"])
print("  suspended shop dashboard /settings/setup:", code)
code, b = api("PATCH", f"/admin/merchants/{ph['id']}", s["owner"], {"status": "active"})
check("owner reactivates", code == 200, code)
code, mid, t0 = wa_msg(ph["phone_id"], cust, text="hi")
settle(t0)
check("reactivated shop answers again", len(outbox(to=cust, since=t0)) >= 1)

suite("W7 Whish callback is gone (no Whish API)")
oid = tj('echo json_encode(App\\Models\\Order::withoutGlobalScopes()->where("merchant_id","' + r["id"] + '")->value("id"));')
for res in ("success", "failure"):
    code, b = api("GET", f"/webhooks/whish/{oid}/{res}")
    check(f"old Whish {res} callback address no longer exists", code == 404, (code, str(b)[:120]))
