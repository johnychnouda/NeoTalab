# NeoTalab security audit (summary, 30 October 2026)

The detailed table of roles, findings and evidence is [SECURITY-AUDIT.md](SECURITY-AUDIT.md) (updated today for the Whish
change). This file is the checkpoint-14 view. All testing was in the lab or the automated suite; nothing destructive was
run against production.

## Re-verified today
| Check | Result | Evidence |
|---|---|---|
| Cross-shop reads and writes, owner-only routes from shop tokens, forged and disabled sessions, over-posting | pass | lab `s03`: 322/322 |
| WhatsApp webhook signature, duplicate delivery, old Whish callback gone (404) | pass | lab `s06`: 33/33 |
| Owner actions, shop deletion leaves no rows or live sessions | pass | lab `s09`: 27/27 |
| Subscription payment: amount comes from the shop's fee, reference unique, editing a shop cannot mark it paid, owner must confirm the check | pass | `s26` 21/21, `SubscriptionPaymentTest`, `WhishTransferTest` |
| Customer "I paid" only works on the customer's own order in the customer's own shop; staff buttons only for the staff's shop | pass by code review (`WhatsAppInboundService`, `OrderConfirmation`) and tests | no new test needed |
| Dependencies | `symfony/mime` advisory CVE-2026-45070 (email header injection) found and fixed; `composer audit` and `npm audit` clean after | D34 |

## Findings this session
| ID | Severity | Component | What | Impact | Status |
|---|---|---|---|---|---|
| D34 | Medium | `symfony/mime` | Header injection in MIME parameter names | Could matter wherever user text reaches an email header (forgot-password, billing mail) | Fixed: 8.1.10, audit clean |
| D35 | Medium (integrity, not confidentiality) | Shop settings API | Letters in the Whish number silently disabled Whish | A shop could lose its payment option without knowing | Fixed, regression test |
| D36 | Low | Shop settings API | Payment window had no limits server-side | A shop could hold a customer's order open for years or cancel orders in 1 minute | Fixed, regression test |

## Open items
- **Request limit and real users.** Measured 30 Oct: 2 to 4 calls per page and about 12 a minute left open, against a limit of 120 a minute. Not a concern for normal use; the 429s in my browser suite came from fast automated clicking.
- **D37 (fixed 30 Oct):** the real AI could repeat a customer-invented price as if it were true (the cart stayed correct). A server check now replaces such replies. Only digits the customer typed are caught.
- **Forwarded-address header on Railway**: the sign-in limits no longer depend on it (D17), the per-address limits still do.
  Needs an answer from Railway support.
- **Not tested this session:** SSRF and path traversal (no code path takes a user URL or path except menu import, tested at
  checkpoint 3), CSRF (token API, no cookies), file upload beyond the menu import, penetration of the live site (stopped at
  checkpoint 3 by the session's safety check after 48 sign-in attempts; not resumed).
- **No external penetration test** has been done. This audit is my own work.
