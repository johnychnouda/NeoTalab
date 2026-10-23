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

## Blockers (need the owner or an outside party)
- **Live WhatsApp and Whish**: Meta App Review and Access verification are in review (submitted 23 Oct).
  Internal behaviour is tested with stand-ins; nothing live is claimed.

## Needs a decision
- Which forwarded header Railway guarantees (ask Railway support, or confirm in the dashboard), so per-visitor limits can trust it.

## Backlog (next exact tasks, in order; items 1–5 done at checkpoint 1, 6–9 at checkpoint 2, 10 at checkpoint 3)
11. Join form abuse: wrong country, bad number, hidden bot field, more than 5 per hour, duplicates.
12. Billing commands: reminders, paid period ending, nightly report numbers versus the database.
13. Flood limit (40 messages / 10 minutes) and blocked numbers.
14. Problem alerts: recorded, emailed/sent, resolved.
