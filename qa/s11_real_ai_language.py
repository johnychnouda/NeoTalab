"""Real AI: every reply (assistant text and the bot's own buttons/messages) in the customer's language,
at a shop whose own language is French."""
import re
from lib import *

s = state(); R = s["shops"]["rest"]
RUN = str(int(time.time()))[-5:]
FR = re.compile(r"\b(vous|votre|nous|bonjour|livraison|j'ai|désolé|touchez|commande|souhaitez|voulez)\b", re.I)
CASES = {
    "en": ["Hi, I'd like 2 zaatar manousheh please", "what time do you close today?", "how much is delivery to Hamra?", "do you have sushi?"],
    "fr": ["Bonjour, je voudrais 2 manouchés au zaatar", "Vous fermez à quelle heure aujourd'hui ?", "Combien coûte la livraison à Hamra ?", "Vous avez des sushis ?"],
    "ar": ["مرحبا، بدي ٢ منقوشة زعتر", "أيمتى بتسكروا اليوم؟", "قديش التوصيل على الحمرا؟", "عندكن سوشي؟"],
    "arabizi": ["marhaba, baddi 2 man2oushe zaatar", "shou l dawam lyom? ade btsakro?", "adesh l delivery 3al Hamra?", "fi 3andkon sushi?"],
}


def language_of(out):
    arabic = len(re.findall(r"[؀-ۿ]", out)); latin = len(re.findall(r"[A-Za-zÀ-ÿ]", out))
    if arabic > latin:
        return "ar"
    return "fr" if FR.search(out) else "latin"


suite("Real AI: replies in the customer's language (French-language shop)")
results = {}
for lang, msgs in CASES.items():
    good = 0
    for i, m in enumerate(msgs):
        c = f"961792{RUN}{list(CASES).index(lang)}{i}"
        code, mid, t0 = wa_msg(R["phone_id"], c, text=m)
        replies = texts([x for x in wait_reply(t0, c) if x["body"].get("to", "").lstrip("+") == c])
        bodies = [re.sub(r"\[(BTN|LIST)[^\]]*\]", "", x) for x in replies]
        langs = [language_of(b) for b in bodies if b.strip()]
        want = {"en": "latin", "arabizi": "latin", "fr": "fr", "ar": "ar"}[lang]
        ok = bool(langs) and all(l == want for l in langs)
        good += ok
        print(f"  {lang:8} {'ok ' if ok else 'BAD'} {langs} | " + " || ".join(b.strip()[:90].replace(chr(10), ' ') for b in bodies))
    results[lang] = good
    check(f"{lang}: every message of the reply in the customer's language", good == len(msgs), f"{good}/{len(msgs)}")
