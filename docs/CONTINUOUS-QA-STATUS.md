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

## Blockers (need the owner or an outside party)
- **Live WhatsApp and Whish**: Meta App Review and Access verification are in review (submitted 23 Oct).
  Internal behaviour is tested with stand-ins; nothing live is claimed.

## Needs a decision
- Ask Railway support which forwarded-address header is guaranteed and whether callers can overwrite it. Not blocking: the login limits no longer depend on it.

## Backlog (next exact tasks, in order; items 1–5 done at checkpoint 1, 6–9 at checkpoint 2, 10 at checkpoint 3)
