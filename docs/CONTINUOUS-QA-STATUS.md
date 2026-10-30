# Continuous QA: status

Updated at every checkpoint. History: [QA-RECORD-2026-10-22.md](QA-RECORD-2026-10-22.md) ·
matrix: [CONTINUOUS-QA-TEST-MATRIX.md](CONTINUOUS-QA-TEST-MATRIX.md) · bugs: [CONTINUOUS-QA-BUG-LOG.md](CONTINUOUS-QA-BUG-LOG.md).

## Checkpoint 0, 23 October 2026 (start of the continuous loop)
- Backend: 437 automated tests pass; Pint clean; web unit tests 10 pass.
- Defects found so far: 13 entries (D1–D13), all fixed with regression tests and live on main.
- Lab: `qa/setup_lab.sh` (SQLite, stand-in WhatsApp, fake AI) and a MySQL lab for races.

## Checkpoint 1, 23 October 2026
- Tested: sessions and sign-in limits (24/24), the live API from outside (read-only), shop-phone commands
  (10/10), timers (13/13), the Whish journey against a Whish stand-in (20/20).
- Fixed: D14 (PHP version label, missing API protection headers). Added a lab-only Whish address
  (`WHISH_BASE_URL`, ignored in production; `WhishPaymentTest::test_a_lab_whish_address_is_never_used_in_production`).
- Backend 439 passed; Pint clean; web unit 10 passed.

## Checkpoint 2, 23 October 2026
- Tested: ratings, delivery areas on the map, split cash and lira, offers with dates (`qa/s19`, 20/20 after fixes).
- Fixed: D15 (a typed quantity became a rating; a typed number now answers "how many?"), D16 (owner shop API dropped the country).
- Backend 441 passed; Pint clean; web unit 10 passed.

## Checkpoint 3, 23 October 2026
- Tested: menu import (21/21); live API protections confirmed after deploy (D14); the visitor-address limits.
- Fixed: D17 (high): a faked forwarded address dodged the sign-in and join limits; now limited per email and overall.
- No more probing of the live site: it was stopped by the session's safety check after the 48 sign-in tries above.

## Checkpoint 4
- Tested: join form abuse (13/13), WhatsApp flood limit (40 of 45 answered), blocked numbers, problem alerts (4/4).
- Lab note: my queue worker had stopped; the flood check showed 0 replies until it was restarted. The old
  failed job is a 22 Oct SQLite lock error (lab only).
- Backend tests: see the commit message.

## Checkpoint 5 (last backlog item)
- Billing reminders, paid-period end and trial end: covered by existing tests (re-checked).
- Nightly report figures checked against the database: `ReportFiguresTest` (2/2). Correct; my own expected
  figure was wrong once (the late order counts as a delivered order), so the test was corrected.
- Railway forwarded-address question: Railway's documentation does not settle it (forum answers conflict,
  see the links in the status discussion). Open: ask Railway support. The fix does not depend on it.

## Checkpoint 6 (your list: backups, refunds, failed deliveries, staff, forgot password)
- Done and tested (backend, 457 passing): staff cannot use the dashboard; "refunded <number>" closes a refund;
  driver "Not delivered" closes the order and tells the shop; forgot-password email (link to the website, once only,
  team members excluded); a shop's real email becomes its login (join approval, or set by the owner).
- Backup restore: a dump restored into a copy, 7 tables equal. The app's own backup command fails on this computer
  because its MySQL tools are a different version than the live server's; production not verified.
- BLOCKED: website build stops on a Next.js security check. Not bypassed. Needs your decision (upgrade Next.js).
  Until then the "Forgot password?" pages are not published.
- NOT DONE by me: an uptime alert needs your UptimeRobot sign-up.
- Shops created before this change keep a placeholder login email: the owner must set a real one.

## Checkpoint 7 (your last list)
- Next.js upgraded 16.4.0 → 16.4.3 (critical advisory fixed); forgot password works in a real browser end to end.
- Lira menu rows without a shop lira rate are refused (was saved as dollars). Test added.
- Menus by shop type: 8 of 9 kinds pass; services: CSV cannot set the duration, and the greeting does not list services.
- Uptime alert: NOT done; needs the owner's UptimeRobot sign-up.
- Salon gaps fixed: a file can set a service's minutes (column 'Duration'; 'Service' is a name header);
  a salon's first reply lists its services with minutes and prices. Menus by shop type: 9 of 9 kinds pass (144/144 checks in the last run).

## Checkpoint 8, 24 October 2026 (production-readiness pass)
- Baseline before changes: backend 460 passed, Pint clean, web unit 10 passed, web build OK, `composer audit` and `npm audit` clean.
- New reports: [PRODUCTION-READINESS-STATUS.md](PRODUCTION-READINESS-STATUS.md), [MERCHANT-COMPATIBILITY-MATRIX.md](MERCHANT-COMPATIBILITY-MATRIX.md),
  [AI-SAFETY-EVALUATION.md](AI-SAFETY-EVALUATION.md), [SECURITY-AUDIT.md](SECURITY-AUDIT.md), [TECHNOLOGY-UPGRADE-AUDIT.md](TECHNOLOGY-UPGRADE-AUDIT.md).
- Fixed D18–D24 (AI and stock safety), each with a regression test that fails without the fix.
- Real AI attack suite `qa/s24_ai_adversarial.py`: 23/24 (my expected total was wrong), then 24/24 twice.
- After changes: backend 471 passed, Pint clean.
- Lab note: an older lab from a previous session still holds ports 8011/8019; this lab ran on 8031/8039
  (`QA_API`, `MOCK_GRAPH_PORT`). Stopping the old processes was refused by the session's safety check.

## Checkpoint 9, 25 October 2026 (audit of the 24–25 Oct features)
- Baseline: backend 507 passed, Pint clean, web unit 10, web build OK, audits clean.
- Fixed D25–D31 (quotes ×3, closing-order race, split cash on MySQL, unneeded AI hand-off, question saved as address), each with a regression test.
- Whole suite now also passes on MySQL 26.10 (515/515); CI job `backend-mysql` added.
- Real AI after the fixes: `s24` 24/24, `s10` 18/18, `s11` 4/4.
- After changes: backend 515 passed (SQLite and MySQL), Pint clean.

## Checkpoint 10, 27 October 2026 (full lab run after the Oct 25 features)
- Baseline before changes: backend 515 passed, Pint clean, web unit 10 passed, web build OK.
- Lab: the existing SQLite lab (`qa/.lab`, not reset). Services started by hand: API on 8011, WhatsApp stand-in on 8039
  (`lab.env` says 8039), queue worker, Whish stand-in on 8029, web on 3011 built against the lab API. The lab's own
  `lab.env` was corrected: `APP_URL` pointed at 8031 (Whish callbacks went to a dead port) and `WHISH_BASE_URL` was missing.
- Lab suites, results after the fixes below:
  - `s03` authz 322/322 · `s05` journey 38/38 · `s06` webhook 36/36 · `s07` salon 13/13 · `s08` customers 28/28 (1 skipped) ·
    `s09` owner 27/27 · `s14` recovery 11/11 (1 skipped) · `s16` shop phone 10/10 · `s17` timers 13/13 · `s18` Whish stand-in 20/20 ·
    `s19` ratings/zones/offers 20/20 · `s20` menu import 21/21 · `s21` join abuse 13/13 · `s22` flood/alerts 8/8 · `s23` menus by type 126/126.
  - `s15` sessions 20/22: the two failing checks are the sign-in limit (30 an hour per email) used up by this session's runs. Retest after the hour.
- Browser (Playwright, lab): desktop Chromium 58/59 (1 skipped by the suite's own conditions) · phone 58/59 · tablet 58/59 ·
  Firefox 57 + 1 failure that passed alone on rerun (flaky under load) · WebKit 55 + 3 failures that passed alone on rerun (15/15).
  Failures in the combined run were the sign-in limit (429 "Too Many Attempts"), not page bugs.
- Not run: `s04`, `s04b`, `s13` (need the MySQL lab, not running); `s10`, `s11`, `s12`, `s24`, `s25` (real OpenAI calls, paid; not run this time).
- Fixed: D33 (high for trust, medium for impact): one customer could book the same salon time twice, once with each stylist,
  and the bot confirmed both. Regression test `ServiceProviderTest::test_a_customer_cannot_hold_two_bookings_at_the_same_time_with_two_people`
  (fails before, passes after); live check: second request refused, one booking. The lab queue worker must be restarted after a
  code change: it keeps the old code in memory.
- Stale lab tests corrected (the product was right, the scripts were not): `s03` (staff cannot sign in to the dashboard any more,
  so its token is minted in the lab; validation runs before the shop check, so 422 is accepted when the record check still shows
  no change), `s07` (team of two stylists: one booking per stylist per time, fresh day per run), `s08` (fake AI cannot read Arabic:
  recorded as a skip, real-AI suite covers it; closed message accepted in French and Arabic), `s09` (trials:expire is global:
  other lab shops get a long trial first; the check looked for a clinic shop, which no longer exists), `s14` (no Meta-approved login template in the lab: the app says so and
  gives the owner the code; the WhatsApp delivery check is a skip), `s20` (re-runs count as updated), `s23` (kg items ask 1 kg, pharmacy is now "other").
- Open, not changed: a repeated booking for the same time gets "that time was just taken" (accurate, not ideal wording).
  The Arabic fallback reply in the stand-in is English for an English shop.
- Blocked: live WhatsApp (Meta template not approved), live Whish, OpenAI-backed suites (not run), MySQL race suites (not run).

## Checkpoint 11, 28 October 2026 (lab rerun after the 27 Oct run)
- Lab: same SQLite lab, services restarted by hand (API 8011, WhatsApp stand-in 8039, Whish stand-in 8029, queue worker).
  The MySQL lab was not used (reading the app's database settings was denied, so `s04`, `s04b`, `s13` are not run).
- `s15` sessions 22/22 after a lab-only fix: the grocer account's password was reset in the lab database to a known
  value, the lab's sign-in counters were cleared, and the brute-force check now waits a minute first (earlier logins used the
  per-minute allowance, so the check started with 429s). The product behaved correctly.
- Background run of the other lab suites (`s03`, `s05`–`s09`, `s14`, `s16`–`s23`): 11 failing checks, all in `s03` and `s05`.
- `s03` authz: the over-posting check compared against a fixed 2099 date, but `s09` sets every other lab shop's trial to
  2099 during its setup. The check now compares with the value before the attempt. Rerun: 322/322. The earlier
  `GET /customers` 500 was SQLite "database is locked" while suites wrote to the same lab database at once; not seen in the quiet rerun.
- `s05` journey: the lab driver was still assigned to order #2 from 27 October, so the app correctly gave no new order.
  That driver was freed in the lab database only. Rerun: 37 of 38 pass. The one failure was the script, not the product:
  after "Delivered", the app handed the driver an older waiting order (#8, left from an earlier run). That is the
  dispatcher's normal behaviour (`DriverDispatcher::dispatchWaiting`). The check now asks only whether the driver is still on
  this run's order. A second run then found both lab drivers still busy with leftover orders, so the lab's open orders for the
  restaurant shop were cancelled in the lab database and its drivers reset (lab only). Rerun: 38 of 38 pass.
- Not run: `s04`, `s04b`, `s13` (MySQL lab), `s10`, `s11`, `s12`, `s24`, `s25` (paid OpenAI calls).

## Checkpoint 12, 29 October 2026 (Whish without the API; subscriptions recorded by the owner)
- Changed: Whish payments no longer use the Whish API. After the shop accepts, the customer gets the shop's Whish number,
  the amount and the order number for the note; "I paid" (`wh:paid`) moves the order to `awaiting_check`; the shop taps
  Received or Not received. No payment in the window cancels the order (`CheckWhishTransfer`). Customers can pay in USD or LBP.
  Removed: `WhishPayService`, the callback route and controller, `CheckWhishPayment`, the Whish config, the lab Whish stand-in.
  Migration `2026_10_29_000001` deletes stored Whish channel and secret values (not recoverable).
- Changed: a shop becomes paid only by a recorded payment (`POST /admin/merchants/{id}/payments`) with a unique Whish reference.
  The amount is the shop's own fee. Paying early adds to the current end date. Editing a shop cannot mark it paid.
  New table `subscription_payments` (migration `2026_10_29_000002`), owner billing screen has a "Record Whish payment" action.
- The follow-up checks added earlier today for late Whish payments belonged to the removed API flow and are gone with it.
- Backend: 513 passed (Pint clean). New tests: `WhishTransferTest` (15), `SubscriptionPaymentTest` (9). Replaced:
  `WhishPaymentTest` (29, removed with the API). Old subscription tests now record payments instead of editing shops.
- Web: unit 10 passed; `next build` OK (includes the owner billing and shop settings screens). Not clicked in a browser yet.
- Lab (migrated the lab database only, lab worker restarted): `s05` 38/38 · `s06` 33/33 · `s03` 322/322 · `s07` 13/13 (the past-date
  check now compares counts; old bookings sit on that day) · `s08` 28/28 · `s09` 27/27 · `s14` 11/11 · `s15` 22/22 (the suite
  now resets its own password first) · `s16` 10/10 · `s17` 13/13 · `s19` 20/20 · `s20` 21/21 · `s21` 13/13 · `s22` 8/8 · `s23` 126/126 ·
  `s26` 19/19 (new: live Whish transfer flow and owner payment recording).
- Removed from the lab: `s18_whish.py` and `mock_whish.py`. `s06` now expects the old callback address to return 404.
- Not run: `s04`, `s04b`, `s13` (MySQL lab, not available); `s10`, `s11`, `s12`, `s24`, `s25` (paid OpenAI calls).
- Open, needs a decision or outside help:
  - Staff alerts for a payment check reach the shop only inside WhatsApp's 24-hour window. Outside it, a Meta-approved
    template is needed (none exists for this yet).
  - Whish-to-Whish payments are checked by the shop, by hand. A customer who forgets the order number can only be matched by
    amount and time.
  - A payment that arrives after the order was cancelled is not flagged automatically; the shop has to notice it and refund.
  - Subscription payments: the owner checks the NeoTalab Whish account by hand; this replaces the "never by hand" rule
    (memory updated to match).
  - Production needs `php artisan migrate` after deploy (two new migrations).
  - Nothing committed or pushed in this checkpoint.

## Checkpoint 13, 29 October 2026 (open items from Checkpoint 12, fixed)
- Payment check reaches the shop outside WhatsApp's 24-hour window through the template `neotalab_payment_check`
  (submitted to Meta by `templates:sync`; it works only once Meta approves it). The alert repeats 30 minutes later if
  the shop has not checked (`RemindShopOfWhishCheck`).
- The check message names the Whish note, the amount and the customer's number, so the shop can match the transfer.
- Late payments: the expired message has an "I paid" button for a day. A late "Received" on a cancelled order sets
  `refund_due` and tells the customer. "Not received" leaves it unpaid. "I paid" after a day is ignored.
- Owner records a subscription payment only after ticking "I checked this transfer in the NeoTalab Whish account"
  (`confirmed_check`, required by the API and the billing screen).
- Browser check (lab, phone, desktop, Arabic, French): billing list, "Record Whish payment" form with the check box
  (button disabled until ticked), a recorded payment moved the shop to paid and the totals updated.
- Deploy: `deploy/STEP-BY-STEP.md` now says new migrations need running (`RUN_MIGRATIONS` is false after the first deploy).
- Backend 520 passed (Pint clean). Web unit 10 passed, build OK. Lab: `s26` 21/21 (new late-payment checks), `s03` 322,
  `s05` 38, `s06` 33, `s07` 13, `s08` 28, `s09` 27, `s14` 11, `s15` 22, `s16` 10, `s17` 13, `s19` 20, `s20` 21, `s21` 13,
  `s22` 8, `s23` 126.
- Lab fixes (test setup only): `s03` compares with the status before each attempt (a real recorded payment is allowed);
  `s15` owner password taken from the lab config (set to the lab test value); `s05` lab leftovers cleared again.
- Still open: the shop settings screen has not been clicked in a browser (needs a shop login); the payment-check
  template needs Meta approval before it can reach a shop outside the 24-hour window.
- Old Whish order columns dropped (owner decision, 30 October 2026): migration `2026_10_30_000001_drop_old_whish_order_columns`
  removes `whish_external_id` and `whish_collect_url`. Rolled back and re-applied on the lab; backend 520 of 520 pass; Pint clean.
  The old values are gone for good; `down()` only restores empty columns.

## Checkpoint 14, 30 October 2026 (autonomous readiness pass on the Whish-by-transfer commit)
- Baseline: backend 520 passed, Pint clean, web unit 10, `npm audit` clean, `composer audit` showed one new advisory (D34).
- Fixed: D34 (`symfony/mime` 8.1.10), D35 (non-numeric Whish number silently switched Whish off), D36 (payment window unchecked).
  Cleaned up after the Whish API removal: the `/pay/*` branch in `web/proxy.js` and the two stale `/pay/*` page checks in `e2e/public.spec.js`.
- After the fixes: backend 522 passed, Pint clean, web unit 10.
- Lab (restarted the stale queue workers; lab database only: old open orders cancelled and drivers freed):
  `s03` 322/322 · `s05` 38/38 (29/9 before the reset: leftover orders held the drivers) · `s06` 33/33 · `s09` 27/27 · `s26` 21/21.
- Browser, lab: new `e2e/whish-number.spec.js` passes on desktop, phone and tablet. Full desktop and phone runs: 54 passed, 3 failed,
  1 skipped each; every failure is a 429 from the API's request limit when the suite runs back to back, and the failing tests pass alone.
- Not run: MySQL suites, real-AI suites, tablet/Firefox/WebKit full runs, load.
- Consolidated reports: `docs/NEOTALAB_*.md` (eight files).

## Blockers (need the owner or an outside party)
- **Live WhatsApp and Whish**: Meta App Review and Access verification are in review (submitted 23 Oct).
  Internal behaviour is tested with stand-ins; nothing live is claimed.

## Needs a decision
- Ask Railway support which forwarded-address header is guaranteed and whether callers can overwrite it. Not blocking: the login limits no longer depend on it.

## Backlog (next exact tasks, in order; items 1–5 done at checkpoint 1, 6–9 at checkpoint 2, 10 at checkpoint 3)

### Checkpoint 14, later on 30 October
- Pushed to `main`; Railway redeployed. `migrate:status` in the production console: all migrations Ran (batches 26 to 27).
- Meta App Review approved 30 Oct (owner's screenshot). Business verification and a live number: not confirmed.
- MySQL 26.10: backend 522/522; the three new migrations roll back and re-run; stock races (`s04`, `s04b`) pass; `s13` speed fine.
- Real AI on the production model: `s10` 18/18; `s24` 23/24 then 24/24; `s11` 3/4 twice; `s25` 15/16 twice. New open finding D37 (medium).
- Dashboard request volume: 2 to 4 calls per page, about 12 a minute left open (limit 120).
- Production backup: newest file restored into a scratch database on the production MySQL; matches live; scratch database dropped. Production has no shops yet.
