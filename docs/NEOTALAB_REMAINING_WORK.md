# NeoTalab remaining work (30 October 2026)

No known open defect. Everything below is either an outside action, something never tested, or a missing feature.

## Before any real customer (owner or outside party)
1. Meta approval: App Review, access verification, and the `neotalab_payment_check` template. Then one real end-to-end order on the live number.
2. One real Whish transfer through the flow: customer pays a shop number, taps "I paid", shop confirms. Also one subscription payment recorded by the owner.
3. Restore the newest real backup file into an empty database and check it (steps in [PRODUCTION-READINESS-STATUS.md](PRODUCTION-READINESS-STATUS.md)).
4. Uptime alert (UptimeRobot or similar).
5. Confirm in Railway that the three 29 and 30 Oct migrations ran (`php artisan migrate`; `RUN_MIGRATIONS` is off after the first deploy).
6. Check Owner → Inbox → System for failed checkouts from the split-cash bug (D29), if any shop took such orders before the fix.

## Not tested yet (could hide defects)
- MySQL suite and race scripts on today's code (`s04`, `s04b`, `s13`).
- Real-AI suites after the Whish change (`s10`, `s11`, `s12`, `s24`, `s25`; paid).
- Tablet, Firefox and WebKit full browser runs after today's changes.
- Email delivery through Resend.
- Dashboard request volume against the 120 per minute limit.
- Real voice notes and images from Meta.
- A second, independent security test.

## Product gaps (build, defer or exclude)
| Gap | Advice |
|---|---|
| Deposits and partial payments (salons, made-to-order, repairs) | build after the pilot, needs the Whish transfer flow proven |
| Substitution rule for groceries | build, small |
| A payment that arrives after an order was cancelled is flagged only when the shop taps Received | defer |
| Free-text AI replies are not checked against shop data | build a check on money figures |
| Whish-to-Whish matching is by hand (a customer who forgets the order number is matched by amount and time) | accept for the pilot |
| Staging environment on Railway | build before the next big upgrade |
| Wholesale tiers, multi-branch, digital products, rentals, classes | exclude until paying demand |
| Reschedule as one step (now cancel then book) | defer |

## Decisions waiting
- Which forwarded-address header Railway guarantees (ask Railway support).
