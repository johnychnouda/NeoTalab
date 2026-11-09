# NeoTalab full operation audit (9 November 2026)

Branch: `audit-full-operation` (from `main` at 6df7041). Scope kept inside the owner's rules: no real WhatsApp message, no real payment, no production data, no real credentials committed. All runs used the throwaway QA lab (SQLite, a stand-in for Meta, made-up shops and phones). The OpenAI API was the one real service called, with the owner's local key, never written to a file in git (`qa/.lab/` is git-ignored).

## 1. System in one page
Laravel 13 API (`backend/`), Next.js 16 site, owner portal and shop dashboard (`web/`), MySQL on Railway, database queue. Each shop's own WhatsApp number is the bot (coexistence). Customer messages arrive by webhook, a queued job runs the assistant (GPT-6 Luna, structured output), and Laravel validates and executes every action. Details: `docs/ARCHITECTURE.md`.

## 2. Feature matrix (what was exercised this audit)
| Area | How tested | Result |
|---|---|---|
| Whole backend | 623 PHPUnit tests, full run | 623 passed |
| Tenant isolation (catalog, conversations, tenants) | PHPUnit | 14 passed |
| Webhook signature and abuse | PHPUnit + lab (s06) | 5 + 33 passed |
| AI action safety (injection, price changes) | PHPUnit + real AI (s24) | 14 passed + 21 of 22 (1 false alarm, see Luna report) |
| Authorization of every API route by role | lab (s03) | 235 passed (first run had 1 failure, a lab-state artefact, see below) |
| Owner portal flows | lab (s09) | 26 passed after a stale-name fix |
| Whish transfer and typed ✅ | lab (s26) | 21 passed |
| Sign-up abuse and rate limits | lab (s21) | 13 passed |
| Flood and alerts | lab (s22) | 8 passed |
| Real-AI chat, orders, languages | lab (s53, s10, s11, s25) | all passed |
| Full order, real AI | scratch script | order created, 3 AI calls, 8 messages |

## 3. Business types
Audit prompt lists pharmacies, clinics, salons, bookings and staff. These were removed on purpose on 8 to 9 Nov (see `docs/DECISIONS.md`), and sign-up accepts four types only (Restaurant / Café, Bakery / Sweets, Supermarket / Grocery / Butcher, Online Shop / Retail). They were **not** re-added, and no order flow for them was tested.

## 4. Findings and fixes (commits on the audit branch)
| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | Voice-note price in the usage table was $3.00 per million; OpenAI lists $1.25 | Low (owner's cost report overstated voice cost) | Fixed, test pins it |
| 2 | Meta rate default in the usage report was $0.0091 (the pre-Oct-2026 card); current card shows $0.0105 | Low to medium (shop's Meta estimate about 15% low) | Fixed, test pins it; confirm in WhatsApp Manager |
| 3 | `qa/s09_owner.py` expected the removed shop name "QA Water Wholesale" | QA only | Fixed |
| 4 | `qa/s05_journey.py` and `qa/s16_shop_phone.py` are stale after the staff removal and the pin-for-delivery rule (s05 reads a missing variable; s16 places a delivery order without a pin) | QA only | **Not fixed.** Next step: rewrite both to use the pickup path or share a pin |
| 5 | `qa/s24` Arabizi check is a word filter that flags a safe refusal | QA only | Not changed (not weakened) |
| 6 | `s03` "set-initial-password refused" fails on a fresh lab and passes after, because the lab admin never set a password first. The API rule is correct (`AuthService::setInitialPassword` refuses once `password_changed_at` is set) | None | Understood; make s01 set the password to remove the false alarm |

No security defect was found: no cross-shop read or write, no price change by prompt, no route open to the wrong role.

## 5. Not tested, and the exact next step
- **Scale and attack scripts (s41 isolation, s48 security, s50 role attacks, s40 to s47):** they refuse to run unless pointed at the separate scale database `neotalab_qa_scale`. Next step: create that database locally, run `qa/scale_env.sh`, then each script.
- **Real WhatsApp, Meta approval, real Whish:** impossible without credentials and would touch real accounts. Meta Access Verification is in review (owner's own step). Whish has no public merchant API found; the shop confirms with typed ✅.
- **Dashboard in a browser, Arabic / French / English at three widths:** not re-run in this audit because no code under `web/` changed. Last run was with the 9 Nov release.
- **Response time under load:** not measured this audit.
