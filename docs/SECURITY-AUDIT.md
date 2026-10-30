# Security and tenant-isolation audit

State on 25 October 2026 (checkpoint 9). Earlier evidence is in [QA-RECORD-2026-10-22.md](QA-RECORD-2026-10-22.md) and
[CONTINUOUS-QA-BUG-LOG.md](CONTINUOUS-QA-BUG-LOG.md); this file is the summary. Tests ran in the lab or
the automated suite only; nothing destructive was run against production.

## Roles and what they can do (server-side checks)

| Role | Where | Allowed | Refused (tested) |
|---|---|---|---|
| Platform owner (`platform-super-admin`) | `/owner` | every `/admin/*` route: shops, join requests, plans, bot, alerts, usage, settings, support sign-in (8-hour, logged) | — |
| Shop owner (`merchant-owner`) | `/backoffice` | own shop's menu, settings, hours, areas, team, customers (block), reports | all `/admin/*` → 403; other shops' ids → 404 (`s03`, `TenantIsolationTest`, `*IsolationTest`) |
| Shop admin (`merchant-admin`) | API | same as shop owner | same; no screen creates this login today |
| Shop staff login (`merchant-staff`) | — | nothing on the dashboard (`StaffDashboardTest`) | all change routes |
| Team member: staff (WhatsApp) | WhatsApp | accept/refuse, ready, out for delivery, open/close, refunded <n>, quote <n> <price>, resume chat; only their shop's orders and quotes | buttons for another shop's order ids; another shop's quote numbers (`QuoteTest`) |
| Team member: driver (WhatsApp) | WhatsApp | start/stop, picked up, delivered, not delivered for orders assigned to them | others' orders (`WhatsAppOperationsTest`) |
| Customer (WhatsApp) | WhatsApp | own cart, orders, bookings | other customers' carts, orders, data (`AiActionSafetyTest`, `s24`) |
| Visitor | website | `/join`, plan, sign-in, forgot password (rate-limited) | everything else |

## Findings

| Area | Result | Evidence |
|---|---|---|
| Cross-tenant reads/writes, IDOR | pass | `s03` (300+ checks), `TenantIsolationTest`, `CatalogIsolationTest`, `ConversationIsolationTest` |
| AI as an attack path (other shop's items, prices, statuses) | pass after D18–D21 | [AI-SAFETY-EVALUATION.md](AI-SAFETY-EVALUATION.md) |
| Sessions: expiry, disabled login, forged token, sign out others | pass | `s15`, `AccountGuardTest` (D1, D2) |
| Brute force, faked forwarded address | fixed (D17) | `TrustedProxyTest` |
| Mass assignment / over-posting | pass; no model uses `$guarded = []` | `s03`, code search |
| SQL injection | no user input in raw SQL (only cast integers and bound values) | code search 24 Oct: `whereRaw`/`selectRaw`/`DB::raw` |
| XSS | dashboard never renders user text as HTML; join-form names tested | `qa/xss.mjs` |
| WhatsApp webhook signature | required; production refuses everything if `WHATSAPP_APP_SECRET` is unset; the "skip signature" switch is ignored in production | `WhatsAppService.php:193`, `s06` |
| Whish callbacks | removed 29 Oct: there is no Whish API or callback any more (the old address returns 404); a payment is marked paid only by the shop (customer payments) or the owner (subscriptions) after checking the Whish account by hand | `WhishTransferTest`, `SubscriptionPaymentTest`, `s06`, `s26` |
| Secrets at rest | WhatsApp tokens and Whish secrets encrypted with APP_KEY (`StoredSecret`); hidden in the owner portal | `StoredSecretTest`, `s09` |
| Secrets in git | none ever committed (history searched for `.env`, OpenAI and Meta token shapes, APP_KEYs) | `git log`, `git grep` 24 Oct |
| Security headers, version leaks | fixed (D14) | `SecurityHeadersTest` |
| Logs and alerts | secrets stripped before alerts are stored | `SystemAlertTest` |
| File upload (menu import) | type/size checked, unsafe files refused | `s20` |
| Two people closing one order at once | fixed (D28): the second change is refused | `OrderTransitionRaceTest`; MySQL parallel runs |
| Database differences (SQLite tests vs MySQL production) | fixed (D29); suite now runs on MySQL in CI | 515/515 on MySQL 26.10 |
| Dependency advisories | none (composer, npm) | [TECHNOLOGY-UPGRADE-AUDIT.md](TECHNOLOGY-UPGRADE-AUDIT.md) |

## Open items (need the owner)

1. (Closed 25 Oct: the old `deploy/secrets-for-render.env` is no longer on this computer. Not checked: whether its `APP_KEY` was the one production uses; if it was, rotate it.)
2. (Closed 25 Oct: pharmacies and clinics are no longer accepted, so health details are no longer collected on purpose.)
3. Database user privileges on Railway: not checked (needs the Railway console).
4. Railway forwarded-address header: still unconfirmed (see status); the sign-in limits no longer depend on it.
5. Uptime alerting: still needs the owner's UptimeRobot account.
