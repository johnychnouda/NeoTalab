# NeoTalab: final test report (3 November 2026)

## Verdict

**CONDITIONALLY READY.**

NeoTalab can carry real shops today at the capacity listed below. It is not "production ready" without conditions, because several things were estimated or not tested at all (listed under "Conditions"). Everything here is my own testing, not an independent audit.

## Your five questions

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | Does it work for shops that are not restaurants? | **Pass** for ordering shops, with two gaps. 120 shops of 20 kinds (pharmacy, bakery, hardware, flowers, books, pet shop, salon, laundry, and others) in English, Arabic and French each took orders or bookings correctly. Gaps: deposits on lira-priced booking shops and job steps on delivery shops are not proven. | scale lab `s40`, `s42`, `s44`; earlier `s27` to `s30` |
| 2 | Does the AI stay reliable over thousands of conversations? | **Pass at 900 customers (about 2,700 messages), not at the planned 3,000.** No invented price, no other shop's item in any cart, no attack moved a price or exposed another shop. After two fixes the failure that remains is 1 customer in 900 told "added" with an empty cart (cause not found). | `s44` real assistant, D44, D45 |
| 3 | Is it safe for 120 merchants at once? | **Pass.** Every protected route refuses a missing or fake login; a shop's login never reaches platform routes or another shop's records; 1,404 hostile query strings, upload, webhook and sign-in tests found one small crash (D49, fixed). Dependencies clean, no secrets in the code or its history. | `s41`, `s48` (54 of 54), `docs/NEOTALAB_SECURITY_AUDIT.md` |
| 4 | Do simultaneous orders stay correct? | **Pass.** 750 customers confirming at once gave 750 orders: no duplicates, correct totals, no other shop's items. It exposed a database deadlock (D43), fixed. | `s42` |
| 5 | Will Railway scale, and what gives first? | **Measured with the fake assistant, estimated with the real one.** See the next section. What gives first is the number of queue workers. | `s45`, Railway metrics |

## Verified capacity (Railway, today's settings: 20 queue workers)

- The webhook accepts every message at every level tested (up to 400 simulated users, 195 requests a second): customers are never refused.
- How long a customer waits for the reply depends on the queue. With the fake assistant (replies instantly), 20 workers answer about **44 messages a second**. Up to 100 simulated users (about 45 messages a second) the first reply came in 1 to 4 seconds. At 200 users it took 24 seconds, at 400 users 67 seconds.
- With 60 workers the same 200 users waited 1.6 seconds and 400 users waited 6.8 seconds. So adding workers works.
- Memory is what Railway charges most for: each worker takes about 50 MB, so 20 workers cost about $10 a month and 60 about $30. After workers, MySQL is next (about 5 of 8 vCPU with 60 workers), then the API.
- **The real assistant is slower:** a message holds a worker about 3 seconds, so 20 workers answer roughly 6 messages a second. That is an estimate from the laptop run, not measured on Railway. For 120 shops at 600 messages a shop a day (about 1 a second on average, perhaps 10 a second at a peak), 40 workers is a sensible start.
- The test ran from a laptop in Lebanon to Railway in California, so every number includes about half a second of network.

## What happens when things break (laptop lab, production's worker settings)

| Failure | Result |
|---|---|
| Every worker killed mid-message | before: the customer got no reply (D47); now: asked to resend after the 150-second retry, nothing done twice |
| Meta unreachable for 20 s | before: 20% of customers got no reply (D48); now: all answered, nobody twice |
| Database down for 20 s | 127 messages refused for the length of the outage (Meta sends them again); workers recovered alone; all 625 customers answered, nobody twice, carts exact |

## Bugs found and fixed in this round

All have a regression test, all pushed to `main` (suite: 665 passing). Details in `docs/CONTINUOUS-QA-BUG-LOG.md`.

| ID | What |
|---|---|
| D43 | Simultaneous order confirms deadlocked the database |
| D44 | "2 Sketchbook" put "Sketchbook Large" in the cart |
| D45 | The assistant added an item and cleared the cart in the same turn, so the cart ended empty |
| D46 | A customer who ordered a sold-out item got no reply at all |
| D47 | A message whose worker died was skipped on retry, so the customer got silence |
| D48 | A short Meta outage dropped replies (and shop alerts) instead of retrying |
| D49 | Sign-in with a list instead of an email crashed with a 500 |

## Conditions (what makes "conditionally" true)

1. **Real-assistant capacity on Railway is estimated, not measured.** Production queue workers were raised from 20 to 40 on 4 November (about $8 a month more memory); watch the wait times, and run one real-assistant load test on a temporary copy before a big launch.
2. **A Railway deploy may stop workers instantly.** The retry covers it (customers wait about 2.5 minutes, then are asked to resend). On 4 November the queue's deploy drain time was set to 60 seconds so workers can finish their current message first; the drain itself was not tested under load.
3. **No long run.** Nothing ran for hours; slow memory growth or a once-a-day job could still hide.
4. **Real WhatsApp delivery at scale and real payments are untested.** Meta was a stand-in; only your first real shops will show how Meta behaves at volume.
5. **No independent penetration test, no commercial scanner, live site not scanned.**
6. **Two small open items:** the 1-in-900 empty cart (cause not found), and the 500 errors at 400 users with 20 workers (about 1.7%, cause not found because Railway dropped the log lines).
7. **Housekeeping:** the temporary Railway project `comfortable-embrace` is stopped but still exists; deleting it needs your 2FA code.

## How this was tested

- Laptop scale lab, isolated from production: 126 shops, 53,000 orders, 13,800 customers; fake assistant for speed, the real assistant for correctness; fake Meta.
- Temporary Railway project for the capacity test (cost about $0.06), a copy of the same data.
- Scripts are in `qa/` (`s40` to `s48`); results are in `docs/SCALE-TEST-2026-11-03.md`.
- Not measured and not claimed: voice notes and photos at scale, real payments, Meta's real delivery and rate limits.

## Production changes made after the report (4 November)

- The seven fixes (D45 to D49 and the recovery code) reached GitHub only on 4 November: my earlier pushes had not gone through and I had not noticed because I hid the push output. Railway deployed them to production on 4 November at about 08:20; `main` now tracks `origin/main`.
- Production queue: `QUEUE_WORKERS` 20 → 40 and `RAILWAY_DEPLOYMENT_DRAINING_SECONDS` = 60. Production health during the redeploy: 18 of 18 checks passed over 3 minutes, all four services online, queue memory 0.7 GB → 1.5 GB.
