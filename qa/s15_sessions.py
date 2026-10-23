"""Sessions: 30-day expiry, support-session expiry, password change, sign out other devices, owner email change."""
import secrets
from lib import *

s = state(); OWN = s["owner"]; G = s["shops"]["grocer"]
suite("Sessions")


def token_row(tok, php):
    tid = tok.split("|", 1)[0]
    tinker(f'DB::table("personal_access_tokens")->where("id",{tid})->update([{php}]);')


# 30-day expiry
code, b = login(G["email"], G["pw"]); t = b["token"]
check("fresh session works", api("GET", "/products", t)[0] == 200)
token_row(t, '"created_at"=>now()->subDays(31)')
check("a session older than 30 days is refused", api("GET", "/products", t)[0] == 401)
token_row(t, '"created_at"=>now()->subDays(29)')
check("a 29-day-old session still works", api("GET", "/products", t)[0] == 200)

# Support session (8 hours)
code, b = api("POST", f"/admin/merchants/{G['id']}/impersonate", OWN)
imp = b["token"]
check("support session opens the shop", api("GET", "/products", imp)[0] == 200)
token_row(imp, '"expires_at"=>now()->subMinute()')
check("support session stops after its 8 hours", api("GET", "/products", imp)[0] == 401)

# Password change signs out the other devices
code, b = login(G["email"], G["pw"]); phone = b["token"]
code, b = login(G["email"], G["pw"]); laptop = b["token"]
code, b = api("PATCH", "/auth/password", laptop, {"current_password": "wrong", "password": "Grocer-new-1", "password_confirmation": "Grocer-new-1"})
check("password change with the wrong current password refused", code == 422, code)
code, b = api("PATCH", "/auth/password", laptop, {"current_password": G["pw"], "password": "short", "password_confirmation": "short"})
check("too-short new password refused", code == 422, code)
code, b = api("PATCH", "/auth/password", laptop, {"current_password": G["pw"], "password": "Grocer-new-1", "password_confirmation": "Grocer-new-2"})
check("mismatched confirmation refused", code == 422, code)
code, b = api("PATCH", "/auth/password", laptop, {"current_password": G["pw"], "password": "Grocer-new-1", "password_confirmation": "Grocer-new-1"})
check("password changed", code == 200 and b.get("token"), code)
fresh = b["token"]
check("the other device (phone) is signed out", api("GET", "/products", phone)[0] == 401)
check("the old laptop session is replaced", api("GET", "/products", laptop)[0] == 401)
check("the new session returned works", api("GET", "/products", fresh)[0] == 200)
check("old password no longer signs in", login(G["email"], G["pw"])[0] == 422)
G["pw"] = "Grocer-new-1"; G["token"] = fresh; save_state(s)

# Sign out other devices
code, b = login(G["email"], G["pw"]); other = b["token"]
code, b = api("POST", "/auth/revoke-other-sessions", fresh)
check("'sign out other devices' works", code == 200 and b.get("token"), code)
check("the other device is signed out", api("GET", "/products", other)[0] == 401)
check("the session that asked gets a new token that works", api("GET", "/products", b["token"])[0] == 200)
G["token"] = b["token"]; save_state(s)

# Owner email change
code, b = api("PATCH", "/admin/account/email", OWN, {"email": G["email"], "current_password": ENV["OWNER_PASSWORD"]})
check("owner cannot take an email already used by a shop", code == 422, code)
code, b = api("PATCH", "/admin/account/email", OWN, {"email": "Owner2@QA.test ", "current_password": ENV["OWNER_PASSWORD"]})
check("owner changes the login email (lower-cased, trimmed)", code == 200 and b.get("email") == "owner2@qa.test", (code, b))
new_owner = b["token"]
check("old owner session signed out", api("GET", "/admin/merchants", OWN)[0] == 401)
check("signs in with the new email", login("owner2@qa.test", ENV["OWNER_PASSWORD"])[0] == 200)
code, b = api("PATCH", "/admin/account/email", new_owner, {"email": ENV["OWNER_EMAIL"], "current_password": ENV["OWNER_PASSWORD"]})
check("changed back", code == 200, code)
s["owner"] = b["token"]; save_state(s)

# Brute force on sign-in
codes = [login(G["email"], "wrong-" + secrets.token_hex(3), retries=1)[0] for _ in range(7)]
check("repeated wrong passwords are slowed down (429 after 5)", codes.count(429) >= 1 and codes[:5].count(422) == 5, codes)
