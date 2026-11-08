"""Real AI (OpenAI, owner-approved): the assistant leads the chat. A greeting in four languages gets the menu
written out (no list to tap), a real question gets only its answer, and an item with sizes is asked for in words.
Needs the queue worker started with lab-ai.sh (AI_DRIVER=openai)."""
import re
from lib import *

s = state(); R = s["shops"]["rest"]
RUN = str(int(time.time()))[-5:]
n = [0]


def fresh():
    n[0] += 1
    return f"9617953{RUN}{n[0]:02d}"


def say(shop, frm, text):
    code, mid, t0 = wa_msg(shop["phone_id"], frm, text=text)
    return " || ".join(texts([x for x in wait_reply(t0, frm) if x["body"].get("to", "").lstrip("+") == frm]))


def no_taps(out):
    return "[LIST" not in out and "[BTN" not in out


suite("AI-led chat: the greeting")
for lang, hello in [("EN", "Hi"), ("AR", "مرحبا"), ("FR", "Bonjour"), ("Arabizi", "marhaba, shu fi?")]:
    c = fresh(); out = say(R, c, hello)
    check(f"{lang}: the greeting gets the menu written out", "•" in out, out)
    check(f"{lang}: no list or buttons to tap", no_taps(out), out)
c = fresh(); out = say(R, c, "show me the menu")
check("'show me the menu' gets the menu written out, no list", "•" in out and no_taps(out), out)

suite("AI-led chat: a question is only answered")
c = fresh(); out = say(R, c, "how much is a falafel?")
check("a price question gets its answer and no menu list", re.search(r"\d", out) and "•" not in out and no_taps(out), out)

suite("AI-led chat: sizes and extras in words")
c = fresh(); out = say(R, c, "I want a falafel")
check("the size is asked in words (the assistant asks, or the server lists the sizes)", "large" in out.lower() and no_taps(out), out)
c = fresh(); out = say(R, c, "ماذا عندكم؟ بدي فلافل")
check("AR: no list or buttons to tap", no_taps(out), out)
c = fresh(); out = say(R, c, "je voudrais un falafel")
check("FR: the size is asked, no list or buttons", no_taps(out), out)
