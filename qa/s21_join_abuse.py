"""Join form abuse on the lab: wrong country, bad numbers, hidden bot field, duplicates, rate limits."""
import secrets
from lib import *

suite("Join form abuse")
RUN = secrets.token_hex(3)
import subprocess, os
subprocess.run([os.environ["QA_LAB"] + "/lab.sh", "php", "artisan", "cache:clear"], capture_output=True)  # fresh visitor allowance for the rule checks


def fresh_visitor():
    subprocess.run([os.environ["QA_LAB"] + "/lab.sh", "php", "artisan", "cache:clear"], capture_output=True)


def apply(**over):
    fresh_visitor()  # each check is its own visitor; the per-visitor limit is tested at the end
    body = {"shopName": f"QA Join {RUN}", "contactName": "Tester", "businessType": "Restaurant / Café", "country": "LB",
            "whatsapp": f"+9617{secrets.randbelow(900000) + 100000}", "city": "Beirut", "street": "Hamra", "region": "Beirut",
            "acceptedTerms": True}
    body.update(over)
    return api("POST", "/onboarding/apply", None, body)


code, b = apply()
check("a normal Lebanese application is accepted", code in (200, 201), (code, b))

code, b = apply(country="AE", whatsapp="+971501234567")
check("a UAE shop is refused (only Lebanon is open)", code == 422, (code, str(b)[:150]))

code, b = apply(whatsapp="+971501234567")
check("a UAE number on a Lebanese application is refused", code == 422, (code, str(b)[:150]))

code, b = apply(whatsapp="70123456")
check("a number without + and country code is refused", code == 422, (code, str(b)[:150]))

code, b = apply(whatsapp="+961 abc")
check("letters in the number are refused", code == 422, (code, str(b)[:150]))

code, b = apply(whatsapp="+96170" + "0" * 20)
check("an impossibly long number is refused", code == 422, (code, str(b)[:150]))

code, b = apply(companyWebsite="http://spam.example")
check("the hidden bot field, when filled, is refused", code == 422, (code, str(b)[:150]))

code, b = apply(acceptedTerms=False)
check("without accepting the terms it is refused", code == 422, (code, str(b)[:150]))

code, b = apply(shopName="<script>alert(1)</script>Shop", businessType="Other")
check("HTML in the shop name is accepted as plain text (shown escaped in the owner portal)", code in (200, 201), (code, str(b)[:150]))

subprocess.run([os.environ["QA_LAB"] + "/lab.sh", "php", "artisan", "cache:clear"], capture_output=True)
code, b = apply(shopName="A" * 151)
check("a 151-character shop name is refused", code == 422, code)

dup = f"+96171{secrets.randbelow(900000) + 100000}"
first, _ = apply(whatsapp=dup)
code, b = apply(whatsapp=dup)
check("a 2nd application with a number still pending is refused", first in (200, 201) and code == 422 and "pending" in json.dumps(b), (first, code, str(b)[:150]))

code, b = apply(whatsapp="+961700002222", email="not-an-email")
check("a bad email is refused", code == 422, (code, str(b)[:150]))

suite("Join form limits")
subprocess.run([os.environ["QA_LAB"] + "/lab.sh", "php", "artisan", "cache:clear"], capture_output=True)
fresh_visitor()
codes = [api("POST", "/onboarding/apply", None, {"shopName": f"Flood {RUN} {i}", "contactName": "T", "businessType": "Other", "country": "LB", "whatsapp": f"+96170{secrets.randbelow(900000) + 100000}", "city": "Beirut", "street": "Hamra", "region": "Beirut", "acceptedTerms": True})[0] for i in range(7)]
_unused = [apply(shopName=f"Flood {RUN} {i}", whatsapp=f"+96170{secrets.randbelow(900000) + 100000}")[0] for i in range(7)]
check("five applications per visitor per hour, the sixth is slowed down", codes[:5].count(429) == 0 and 429 in codes[5:], codes)
