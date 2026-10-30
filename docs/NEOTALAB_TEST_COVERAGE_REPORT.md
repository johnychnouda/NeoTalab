# NeoTalab test coverage report (30 October 2026)

Counts are what I ran today unless a row says otherwise. Coverage here means which behaviours have a test, not a code-coverage
percentage: no coverage tool was run.

## Automated suites
| Suite | Where | Count | Last run |
|---|---|---|---|
| Backend PHPUnit (feature and unit) | `backend/tests` | 522 pass | 30 Oct, SQLite. MySQL 515/515 on 25 Oct; CI has a MySQL job |
| Web unit | `web/tests` | 10 pass | 30 Oct |
| Browser (Playwright) | `web/e2e` | 58 per browser profile (incl. the new Whish test) | 30 Oct: desktop and phone full runs 54 pass / 3 fail (429) / 1 skip; failures pass alone |
| Lab scripts | `qa/s*.py` | see below | 30 Oct for the five named |
| Lint | Pint | clean | 30 Oct |
| Dependency audit | composer, npm | clean | 30 Oct |

## Lab scripts, what each covers and its last result
| Script | Covers | Result |
|---|---|---|
| `s03` | roles, tenant isolation, sessions, over-posting | 322/322 (30 Oct) |
| `s05` | order from first message to delivered, driver, second customer interference | 38/38 (30 Oct) |
| `s06` | WhatsApp webhook: signature, duplicates, malformed | 33/33 (30 Oct) |
| `s09` | platform owner: shops, trials, deletion | 27/27 (30 Oct) |
| `s26` | Whish transfer flow and subscription payments | 21/21 (30 Oct) |
| `s07`, `s08`, `s14`–`s17`, `s19`–`s23` | salon, customers, recovery, sessions, shop phone, timers, ratings/zones/offers, menu import, join abuse, floods, menus by kind | passed 29 Oct (checkpoint 13); not rerun today |
| `s04`, `s04b`, `s13` | stock races and speed on MySQL | passed 23–25 Oct; not rerun |
| `s10`, `s11`, `s12`, `s24`, `s25` | real AI (paid) | 62/62 on 27 Oct; not rerun |

## Gaps in coverage
- No test of the live WhatsApp or Whish services.
- Wholesale, multi-branch, digital, rentals, classes: no tests because the features do not exist.
- No automated test for how many requests one dashboard session makes (see the security audit's open item).
- Browser suite runs against a build made for the lab; production build behaviour with real data is not exercised.
- Voice messages and images are tested with stand-ins (`s12`); real media from Meta is untested.
- Tablet, Firefox and WebKit full runs were not repeated today (checkpoint 11: 58/59, 57/59, 55/59 before reruns of flaky failures).
