"""Account recovery: the owner sends a shop a new login on WhatsApp; the old password and sessions stop."""
from lib import *

s = state(); OWN = s["owner"]; P = s["shops"]["pharm"]
suite("Recovery: new login sent on WhatsApp")
code, b = api("PATCH", "/admin/platform-settings", OWN, {"wa_phone_id": "PNIDPLATFORM"})
check("platform WhatsApp number set (lab stand-in)", code == 200, (code, str(b)[:150]))
old_token = P["token"]
code, _ = api("GET", "/products", old_token)
check("shop's current session works before", code == 200, code)
t0 = time.time()
code, b = api("POST", f"/admin/merchants/{P['id']}/welcome", OWN, {"otp": "482913", "regenerateAccess": True})
print("  ->", code, {k: b.get(k) for k in ("sent", "message", "welcomeError")} if isinstance(b, dict) else b)
check("owner sends a new login", code == 200 and b.get("sent") is True, (code, b))
msgs = texts(outbox(to=P["wa"].lstrip("+"), since=t0)) + texts(outbox(to=P["wa"], since=t0))
check("the shop's WhatsApp gets the sign-in email and the 6-digit code", any("482913" in m and P["email"] in m for m in msgs), msgs)
code, _ = api("GET", "/products", old_token)
check("the old session is signed out", code == 401, code)
code, _ = login(P["email"], P["pw"])
check("the old password no longer works", code == 422, code)
code, b = login(P["email"], "482913")
check("the new code signs in", code == 200, code)
new = b["token"]
check("…and asks for a new password first", b["user"].get("password_changed_at") in (None, ""), b["user"])
code, b = api("POST", "/auth/set-initial-password", new, {"password": "Pharm-new-pass-1", "password_confirmation": "Pharm-new-pass-1"})
check("shop sets its own password", code == 200, (code, b))
code, b = login(P["email"], "Pharm-new-pass-1")
check("the shop signs in with its own password", code == 200, code)
code, _ = api("POST", f"/admin/merchants/{P['id']}/welcome", OWN, {"otp": "12", "regenerateAccess": True})
check("a code that isn't 6 digits is refused", code == 422, code)
code, _ = api("POST", f"/admin/merchants/{P['id']}/welcome", s["shops"]["rest"]["token"], {"otp": "111111", "regenerateAccess": True})
check("another shop cannot reset this shop's login", code == 403, code)
s["shops"]["pharm"]["pw"] = "Pharm-new-pass-1"; s["shops"]["pharm"]["token"] = b["token"]; save_state(s)
