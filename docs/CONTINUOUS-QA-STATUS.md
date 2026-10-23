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

## Blockers (need the owner or an outside party)
- **Live WhatsApp and Whish**: Meta App Review and Access verification are in review (submitted 23 Oct).
  Internal behaviour is tested with stand-ins; nothing live is claimed.

## Backlog (next exact tasks, in order; items 1–5 done at checkpoint 1)
6. Ratings after delivery, low rating alert.
7. Delivery areas drawn on the map: pin inside, outside, overlapping areas.
8. Split cash in dollars and lira: rounding and the shop's rate.
9. Offers with start and end dates, including an offer ending while the item sits in a cart.
10. Menu import (text and files, lira rows, broken files).
11. Join form abuse: wrong country, bad number, hidden bot field, more than 5 per hour, duplicates.
12. Billing commands: reminders, paid period ending, nightly report numbers versus the database.
13. Flood limit (40 messages / 10 minutes) and blocked numbers.
14. Problem alerts: recorded, emailed/sent, resolved.
