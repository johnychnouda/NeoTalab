"""Response times with a big shop (500 items, 2,000 orders): dashboard API calls and a WhatsApp reply."""
import statistics
from lib import *
import lib

s = state(); r = s["shops"]["rest"]; T = r["token"]; OWN = s["owner"]
suite("Speed with a big shop")


def timed(label, fn, n=20, limit_ms=1000):
    times = []
    for _ in range(n):
        time.sleep(0.6)  # stay under the API's 120 requests/minute, outside the timing
        lib._last.clear()
        t0 = time.perf_counter(); code = fn(); times.append((time.perf_counter() - t0) * 1000)
        assert code in (200, 201), (label, code)
    times.sort()
    p50, p95 = statistics.median(times), times[int(len(times) * 0.95) - 1]
    print(f"  {label:38} median {p50:6.0f} ms   95% under {p95:6.0f} ms")
    check(f"{label}: 95% under {limit_ms} ms", p95 < limit_ms, f"p95 {p95:.0f} ms")


timed("shop: menu list (500 items)", lambda: api("GET", "/products", T)[0])
timed("shop: customers list (400)", lambda: api("GET", "/customers", T)[0])
timed("shop: reports (2,000 orders)", lambda: api("GET", "/analytics/dashboard", T)[0])
timed("shop: setup checklist", lambda: api("GET", "/settings/setup", T)[0])
timed("owner: shops list", lambda: api("GET", "/admin/merchants", OWN)[0])
timed("owner: usage", lambda: api("GET", "/admin/usage", OWN)[0])
RUN = str(int(time.time()))[-5:]
k = [0]


def wa():
    k[0] += 1
    return wa_msg(r["phone_id"], f"9617960{RUN}{k[0]:02d}", text="hi")[0]


timed("WhatsApp: 'hi' answered (incl. AI stand-in)", wa, n=15, limit_ms=2000)
