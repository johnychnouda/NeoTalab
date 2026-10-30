# NeoTalab bugs and fixes

The full table for D1 to D33, with reproduction, root cause, files and tests, is
[CONTINUOUS-QA-BUG-LOG.md](CONTINUOUS-QA-BUG-LOG.md). It now also carries today's entries. All 36 are fixed.

## Found and fixed on 30 October 2026 (checkpoint 14)
| ID | Sev | What happened | Root cause | Fix | Regression test |
|---|---|---|---|---|---|
| D34 | Medium | Mail library had a published header-injection advisory | `symfony/mime` 8.1.7 | updated to 8.1.10 (`backend/composer.lock`) | suite passes; `composer audit` clean |
| D35 | Medium | A shop typed letters as its Whish number, got "saved", and Whish was off | the service stripped non-digits, got an empty string and read it as "turn Whish off" | `MerchantSettingsService`: letters refused with 422; empty still turns it off on purpose | `WhishTransferTest::test_a_whish_number_that_is_not_a_number_is_refused_and_does_not_switch_whish_off` and `web/e2e/whish-number.spec.js` |
| D36 | Low | Payment window took any number through the API | only the screen limited it | server accepts 5 to 120 minutes | `WhishTransferTest::test_the_payment_window_is_kept_between_5_and_120_minutes` |

Both regression tests were run before the fix and failed (200 instead of 422), then passed after.

## Cleanup, not a defect
Removing the Whish API on 29 October left `web/proxy.js` with a branch for `/pay/*` pages that no longer exist, and the browser
suite still expected those pages (2 false failures). Both removed. The web build and unit tests pass after.

## Lab-only problems (not product bugs)
- Two old queue workers held old code in memory; restarted.
- Orders from earlier runs held the lab's drivers, so `s05` failed 9 checks until the lab database was reset (lab only).
- HTTP 429 from the API request limit when browser suites run back to back.

## Files changed this session
`backend/composer.lock`, `backend/app/Services/MerchantSettingsService.php`, `backend/tests/Feature/WhishTransferTest.php`,
`web/proxy.js`, `web/e2e/public.spec.js`, `web/e2e/whish-number.spec.js` (new), and these docs. No migrations.
Two commits on `main`: `7404dfc`, `1b3ce7a` (plus the docs commit). Nothing pushed.
