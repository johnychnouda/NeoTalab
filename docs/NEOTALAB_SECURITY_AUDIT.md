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

## Security pass, 3 November 2026 (scale lab, 120 shops, my own testing)

Script `qa/s48_security.py`, run against the laptop scale lab only (never production). Final run: 54 checks, 54 passed. One finding (D49), fixed.

| Area | What was checked | Result |
|---|---|---|
| Access without a login | All 94 protected routes with no token, a garbage token and a well-formed fake token | all refused (401/403) |
| Roles | All 34 platform-owner routes with a shop owner's token; shop-only routes with the platform owner's token | refused or empty, never served |
| Other shops' records | 18 id routes (read, change, delete) with another shop's ids; the other shop's data afterwards | always 403/404/422; data intact |
| Over-posting | A shop's own PATCH with status, plan, billing, dates, slug, merchant_id; a record posted with another shop's `merchant_id` or a chosen id; another shop's WhatsApp number on both profile routes | sensitive fields unchanged; records land in the caller's shop with new ids; number refused (422) |
| Injection | 1,404 hostile query strings (quotes, UNION, SLEEP, `../`, 3,000 characters) on 12 list routes; array-typed parameters; wrong-typed and huge product bodies; a stored `<img onerror>` name | no 5xx, no SQL text, no delay; the name comes back as JSON data (the web app escapes it) |
| Headers and errors | nosniff, frame protection, referrer policy, no-store on signed-in JSON, no X-Powered-By, CORS with an unknown origin, 404/405/bad-JSON pages, `.env` / `.git` / logs / telescope / vendor files, TRACE | all as wanted, nothing leaked |
| Sign-in | same answer for unknown email and wrong password; lock-out after repeated failures (even with the right password); same answer to forgot-password for known and unknown emails; invalid reset token; token dead after sign-out; "sign out other devices" kills every older token | pass |
| Sign-in input | email as a list/object | **D49: 500, fixed** |
| Webhook | unsigned, wrong signature, signature of another body, signed malformed JSON, signed injection strings, a 3 MB body, wrong verify token | all refused or ignored, no 5xx |
| Upload (menu import) | a PHP file named .csv, a .php file, `../` in the filename, 12 MB, random bytes, empty, spreadsheet formulas | none crashed or leaked; nothing written outside; formulas kept as plain text |
| Secrets in responses | every shop-visible GET route and the owner's shop list | no password, token or key values |
| Flooding | 40 quick posts to onboarding and forgot-password | throttled (429) |

Also checked without running anything: `composer audit` and `npm audit` clean; no key-shaped strings or secret files in the repository or its history; no raw SQL built from user input (the one place that concatenates, `UsageService`, casts to integers first); no `eval`, `exec` or `unserialize`; no server-side fetch of a user-supplied URL (so no SSRF path); the web app has one `dangerouslySetInnerHTML` (fixed company details, no user text) and opens WhatsApp links only with digits-only numbers; web headers set in `next.config.mjs` (HSTS, frame denial, nosniff, referrer policy).

Noted, not changed: shop and owner tokens live in the browser's `localStorage`, so a cross-site-scripting hole would expose them (none found; the cost of changing this is a cookie-based sign-in rewrite); `logo_url` is any text up to 255 characters (the web app does not display it, so no effect today); a shop may edit its own contact email, phone and WhatsApp number (by design, the number must be unique).

Not done: no commercial scanner (ZAP, Burp) or independent penetration test; no scan of the live site; no test of Railway's own network settings; business-logic fuzzing beyond the checkout and AI tests already in the suite; the real AI against new attack types beyond the ones in `s24` and `s44`.
