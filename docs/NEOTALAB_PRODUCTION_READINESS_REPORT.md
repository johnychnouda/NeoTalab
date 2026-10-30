# NeoTalab production readiness report

Written 30 October 2026 at checkpoint 14. This session did **not** redo every journey from scratch. It re-ran the
automated suites and the lab on the current `main`, probed the code added on 29–30 October (Whish by transfer,
subscription payments), and fixed what it found. Everything else rests on checkpoints 0–13, which are recorded in
[CONTINUOUS-QA-STATUS.md](CONTINUOUS-QA-STATUS.md) and [CONTINUOUS-QA-BUG-LOG.md](CONTINUOUS-QA-BUG-LOG.md).
Where a number below comes from an earlier checkpoint, it says so.

## Verdict

**CONDITIONALLY READY: specific issues must be resolved before real customers.**
Ready for a controlled pilot with two or three restaurants or cafés once the blockers below are cleared.
Not ready for an open public launch.

Why: the order engine, tenant isolation, role checks and AI safety rules held in every test run so far (13 checkpoints,
33 bugs found and fixed before this session). What has never been tested is the part customers and money actually
touch: the live WhatsApp number and a real Whish transfer. Both are in Meta's review. A passing test suite does not
cover that.

## Blockers before any real customer
1. **Live WhatsApp never tested end to end.** Update, 30 October: Meta's App Review is now **approved** (owner's screenshot:
   `whatsapp_business_messaging`, `whatsapp_business_management`, `public_profile`, `email`, submitted 30 Oct 02:50). Business
   verification and a real number going live are not confirmed. Everything WhatsApp is still proven against a stand-in only. The new payment-check template (`neotalab_payment_check`) is
   not approved either, so a shop outside WhatsApp's 24-hour window will not get a payment alert yet.
2. **A real Whish transfer has never been made through the flow.** There is no Whish API any more; the shop checks its own
   Whish account by hand. The flow is tested with simulated WhatsApp messages only.
3. ~~Production backup restore~~ **Done 30 Oct.** The newest real backup (01:00 that day) was downloaded from the backup storage inside the Railway console and restored into a scratch database on the production MySQL server (since dropped). Table list and row counts matched the live database, apart from cache, locks, sessions, 3 newer migrations and the new `subscription_payments` table. **Limit:** production has 0 shops, 0 customers and 0 orders (one login), so this proves the mechanism, not a restore of real shop data.
4. **No uptime alert** (needs the owner's UptimeRobot account).
5. ~~Migrations in production~~ **Confirmed 30 Oct** through the Railway console: `migrate:status` shows the three
   29–30 October migrations as Ran.

## Testing statistics (this session, 30 October, run by me)
| What | Passed | Failed | Skipped | Notes |
|---|---|---|---|---|
| Backend PHPUnit (SQLite) | 524 | 0 | 0 | 520 at start; +4 regression tests (D35, D36, D37 ×2). Pint clean |
| Backend PHPUnit (MySQL 26.10) | 522 | 0 | 0 | plus migrations rolled back and re-run (3 steps) |
| Lab stock races on MySQL (`s04`, `s04b`) | 4 checks | 0 | 0 | 2 buyers × 10 rounds, 5 buyers × 6 rounds: never oversold |
| Lab speed on MySQL (`s13`) | 7 | 0 | 0 | see performance report |
| Real AI, production model (`s10`, `s11`, `s24`, `s25`) | 18 + 3 + 23 + 15 | 0 + 1 + 1 + 1 | 0 | `s24` rerun 24/24; `s11` and `s25` miss the same check on both runs. See bugs file (D37) |
| Web unit (Vitest) | 10 | 0 | 0 | `next build` succeeds |
| Lab `s03` authorisation | 322 | 0 | 0 | |
| Lab `s05` delivery journey | 38 | 0 | 0 | first run 29/9: lab drivers still held by old orders; lab reset, rerun clean |
| Lab `s06` webhook | 33 | 0 | 0 | |
| Lab `s09` owner | 27 | 0 | 0 | |
| Lab `s26` Whish transfer and subscription payments | 21 | 0 | 0 | |
| Browser, desktop Chromium, full suite | 54 | 3 | 1 | all 3 failures are HTTP 429 from the lab's request limit; the same 3 pass when run alone |
| Browser, phone Chromium, full suite | 54 | 3 | 1 | same cause (429) |
| Browser, new Whish number test | 3 | 0 | 0 | desktop, phone, tablet |
| `composer audit`, `npm audit` | clean | | | after updating `symfony/mime` |

Not run this session: voice/call suite `s12`, tablet, Firefox and WebKit full runs, load tests beyond `s13`. Last results for those are in the status file.

Defects this session: 4 found, 4 fixed, 0 open (see [NEOTALAB_BUGS_AND_FIXES.md](NEOTALAB_BUGS_AND_FIXES.md)).
Across the project: 37 logged (D1–D33 plus four here), all fixed.

## What I did not do from the brief, and why
The brief asks for every vertical, every role and every persona to be driven from scratch. Not redone here. The
existing record covers them: `s23` (nine shop kinds), `s03` (roles and isolation), `s08`/`s24` (customer personas and
attacks), `s07` (salon). New simulations would repeat them without new information. The open gaps are the ones listed
in [NEOTALAB_REMAINING_WORK.md](NEOTALAB_REMAINING_WORK.md).

## Where each area stands
| Area | State |
|---|---|
| Backend and database | solid in tests; suite passes on SQLite and (checkpoint 9) MySQL |
| Tenant isolation and roles | pass: `s03` 322 checks plus isolation tests |
| AI | rerun 30 Oct on the production model: `s10` 18/18, `s24` 24/24 twice after the D37 guard; amounts the customer typed are no longer repeated as shop prices; other free-text figures are still not checked |
| WhatsApp | simulated only |
| Payments | cash and Whish-by-transfer tested in simulation; nothing automatic, nothing live |
| Security | see [NEOTALAB_SECURITY_AUDIT.md](NEOTALAB_SECURITY_AUDIT.md) |
| Performance | rerun 30 Oct on MySQL lab (fast); not tested on Railway under load; see [NEOTALAB_PERFORMANCE_REPORT.md](NEOTALAB_PERFORMANCE_REPORT.md) |
| Infrastructure | Railway, Laravel 13, PHP 8.5; no staging environment; backup restore verified 30 Oct; production database is still empty of shops |

Related: [NEOTALAB_MERCHANT_COMPATIBILITY_MATRIX.md](NEOTALAB_MERCHANT_COMPATIBILITY_MATRIX.md) ·
[NEOTALAB_TEST_COVERAGE_REPORT.md](NEOTALAB_TEST_COVERAGE_REPORT.md) ·
[NEOTALAB_INTEGRATION_STATUS.md](NEOTALAB_INTEGRATION_STATUS.md).
