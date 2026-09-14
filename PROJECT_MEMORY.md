# PROJECT_MEMORY.md

> **Read this file before starting any task.** It is the single source of truth for architectural
> decisions, naming conventions, folder structure, the API map, database changes, completed
> milestones, and pending work. Update it at the end of every milestone (and whenever a decision
> is made that a future contributor would otherwise have to reverse-engineer).

---

## 1. What NeoTalab is

An **AI-powered WhatsApp Commerce Operating System**: WhatsApp becomes the merchant's storefront,
an AI agent acts as the sales employee, and a dashboard is the management system. The platform is a
**generic, multi-business, multi-tenant SaaS** — it must support restaurants, pharmacies,
supermarkets, retail, services, etc. **No business-type-specific assumptions** in the core.

The authoritative product charter is the **Master Development Prompt** (pasted by the owner on
2026-07-05). Where older docs conflict with it, the Master Prompt wins.

---

## 2. Repository reality (release — 2026-08)

| Path | What it is | Status |
|------|-----------|--------|
| `backend/` | Laravel 12 production API | **Production** — Railway |
| `web/` | Next.js 15 dashboards (owner + backoffice, EN/AR/FR) | **Production** — Railway |
| `deploy/` | Railway runbook + secrets template | **Production ops** |
| `docs/` | Release guides (hosting, WhatsApp, optional local testing) | **Production ops** |
| `render.yaml` | Legacy Render blueprint (unused) | Not the release path |
| `PROJECT_MEMORY.md` | Architecture charter | Living doc |

**Production stack:** Railway (`web/` + `backend/` API + queue + MySQL). Not Vercel. Not TiDB. Not AWS console.
Deploy: [`deploy/STEP-BY-STEP.md`](deploy/STEP-BY-STEP.md).

**Local dev (optional):** Homebrew PHP + Composer · MAMP MySQL · `php artisan serve` + `queue:work`.
Legacy Node/Postgres/static reference code **removed** from repo (2026-08 release cleanup).

---

## 3. Decisions locked this session (2026-07-05)

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | **Backend = Laravel 12 / PHP 8.4** rebuilt in `backend/` | Master Prompt mandate; `backend/` always anticipated this. Node `api/` becomes reference. |
| D2 | **Frontend = keep Next.js `web/`**, extend it | Preserves substantial working UI, i18n, demo mode. (Master Prompt's Flutter requirement deliberately not adopted.) |
| D3 | **Scope = Master Prompt supersedes** locked #1–78 | Generic multi-business, full multi-tenancy, RBAC, automation/RAG/coupons as the charter. |
| D4 | **Tenancy = single DB, shared schema, row-level `merchant_id`** | Scales to thousands of tenants without per-tenant DB ops. Hand-rolled `BelongsToTenant` trait + global scope + tenant-resolution middleware for full control + testability. |
| D3a | **Database = MySQL (via MAMP + phpMyAdmin); native toolchain, no Docker** | User preference. Laravel migrations stay DB-agnostic; UUID PKs → `char(36)`, JSONB → MySQL `json`. `reference/database/schema.sql` (Postgres) is reference only. |
| D3b | **PHP 8.5** (Homebrew default), not 8.4 | Laravel 12 requires `^8.2`; 8.5 satisfies it. Master Prompt said 8.4 but 8.5 is functionally fine. |
| D3c | **Horizon/Reverb + Redis DEFERRED** | No Docker/Redis locally. M1 uses `sync`/`database` queue + file/database cache & sessions. Redis-backed queue/websockets return in a later milestone. |
| D3d | **Tests run on in-memory SQLite** | Lets M1 be built & verified (incl. tenant-isolation gate) without waiting on MAMP; real runtime uses MySQL. |
| D5 | **The tenant is the `merchants` table** (not renamed to `tenants`) | Continuity with existing schema, Next.js app, and product language. Generalized with `business_type` + `settings` JSONB; restaurant-only assumptions dropped. |
| D6 | **Identity split into a `users` table** | Humans who log in are separated from the business. A user `belongs_to` a merchant (nullable for platform admins). Replaces `password_hash` on `merchants`/`drivers`/`owners` + the bespoke `sessions` table. Enables multiple employees per business + RBAC. |
| D7 | **Auth = Laravel Sanctum personal access tokens (bearer)** | Matches the Next.js client, which already stores a bearer token in `localStorage` (`web/lib/api.js`). |
| D8 | **RBAC = spatie/laravel-permission, teams feature keyed on `merchant_id`** | Per-tenant roles/permissions. |
| D9 | **Audit = spatie/laravel-activitylog**, tenant-scoped | Charter requires audit + activity logs. |
| D10 | **API = versioned `/api/v1`**, thin controllers → Services (DI/SOLID), FormRequests, API Resources, Policies | Consistent envelope, business logic out of controllers. |
| D11 | **Drivers stay a tenant-scoped domain entity; driver auth is WhatsApp-first, built in the dispatch milestone** | Not part of the M1 identity foundation. |
| D12 | **AI = provider-agnostic `ChatProvider` interface; structured JSON output only** | Fake driver for tests/local (`AI_DRIVER=fake`); production via OpenAI-compatible API (`AI_DRIVER=openai`, JSON schema mode). Never parse free-form model text. Anthropic can be a third driver later. |
| D13 | **Conversation simulation via HTTP; WhatsApp transport deferred to M4** | M3 endpoints let merchant staff drive turns for testing; M4 webhook will call `ConversationService` directly. |
| D14 | **Repo cleanup (2026-07-05): legacy stack under `reference/`** | Removed Docker/`docker-compose.yml`. Moved Node `api/`, Postgres `database/`, and static previews into `reference/`. |
| D15 | **Release cleanup (2026-08) + host lock (2026-09)** | Production-only repo. Host = **Railway** (web + API + queue + MySQL). Not Vercel, TiDB, or AWS. |

### Roles (spatie, team = merchant_id)
`platform-super-admin` (merchant_id null) · `merchant-owner` · `merchant-admin` · `merchant-staff` · `driver` (reserved).

---

## 4. Conventions

- **Primary keys:** UUID (continuity with existing schema; use Laravel `HasUuids` → `char(36)` in MySQL).
- **Tenant key:** `merchant_id` on every tenant-scoped table; enforced by the `BelongsToTenant` trait's global scope. Platform-admin requests bypass the scope via role.
- **Database:** MySQL. Production = Railway MySQL; local dev = MAMP. Laravel migrations in `backend/database/` are the source of truth. Keep migrations DB-agnostic (`$table->json()`, `$table->uuid()`).
- **Backend layering:** `Controller (Api\V1)` → `FormRequest` (validate) → `Service` (logic, DI) → `Model` → `API Resource` (response). Authorization via `Policy` + middleware. No business logic in controllers.
- **API responses:** consistent JSON envelope via API Resources.
- **Localized fields:** keep `*_ar` / `*_fr` pattern already used in the schema where relevant, or move to a `settings`/translations JSONB — decide per table (M2+).
- **Docs:** update this file when structure/decisions change.

---

## 5. API map

`/api/v1` (M1 auth + M2 catalog + M3 conversations):
- `POST /auth/register` · `POST /auth/login` · `POST /auth/logout` · `GET /auth/me` · password change/reset
- `GET|PATCH /merchant` (current tenant profile)
- `GET|POST /users` · `PATCH /users/{id}` · role assignment (tenant-scoped)
- `GET|POST /admin/merchants` (platform-admin, cross-tenant)
- `GET|POST /categories` · `GET|POST|PATCH|DELETE /products…` · variants · modifier-groups · modifiers (M2 catalog)
- `POST /conversations` · `GET /conversations/{id}` · `POST /conversations/{id}/turns` (M3 — simulate AI turns; merchant roles only)

_(Later milestones append orders, whatsapp webhooks, dispatch, analytics endpoints here.)_

---

## 6. Database changes log

- **M1 (done):** `merchants` (generic business_type + settings JSONB), `users` (UUID, nullable
  `merchant_id`, phone, status), `platform_settings` (singleton), plus package tables: sanctum
  `personal_access_tokens`, spatie permission tables (no teams), spatie `activity_log`. All morph
  keys switched to UUID (tokenable, model_has_*, activity_log causer/subject). No DB-level FK on
  `users.merchant_id` (SQLite-safe; app-enforced). Deferred: products/categories/modifiers/orders/
  drivers/zones/tracking/analytics/whatsapp_messages.
- Verified on **MySQL 8.0.44 via MAMP** (`neotalab` db, 18 tables) and on in-memory SQLite (tests).
- **M2 (done):** catalog tables — `categories`, `products` (generic + `attributes` JSON + inventory:
  `is_available` toggle, optional `track_inventory`/`stock_quantity`/`low_stock_threshold`),
  `product_variants` (absolute `price`, NULL = inherit), `modifier_groups` + `modifiers`
  (additive `price_delta`, min/max select rules). All tenant-scoped with own `merchant_id`
  (never derived via join). SKU unique per tenant. No DB-level FKs — child cleanup is explicit
  in `CatalogService` transactions. Migrated on MAMP MySQL (22 tables total).
- **M3 (done):** AI conversation engine — `customers` (phone, locale, name, block flag),
  `conversations` (status, channel, JSON state/history), `carts` + `cart_items` (resolved catalog
  lines with snapshotted prices). Provider-agnostic `ChatProvider` interface with `FakeChatProvider`
  (default/tests) and `OpenAiCompatibleChatProvider` (JSON-schema structured output via
  `AI_DRIVER=openai`). Services: `ConversationService`, `CartService`, `CatalogResolver`. Simulation
  endpoints under `/api/v1/conversations`. No DB-level FKs. Migrated on MAMP MySQL (26 tables total).
  Deferred to later: RAG knowledge base, Anthropic-native driver, delivery/fees on cart totals.

---

## 7. Milestone log & roadmap

| M | Title | Status |
|---|-------|--------|
| **M1** | **Multi-tenant DB + auth foundation** (tenancy, identity, Sanctum, RBAC, audit, `/api/v1` auth+provisioning, tenant-isolation tests) | **✅ Complete** — 20 tests green; verified live on MySQL/MAMP |
| M2 | Catalog — generic products/categories/variants/modifiers/inventory | **✅ Complete** — 33 tests green (13 catalog); `/api/v1` catalog CRUD; demo catalog seeded on MySQL. Deferred to later: media uploads, import/export, advanced pricing |
| **M3** | **AI conversation engine** — structured outputs, cart engine, simulation endpoints | **✅ Complete** — 47 tests green (14 conversation); Fake + OpenAI-compatible drivers; `/api/v1/conversations`. Deferred: RAG knowledge base, Anthropic driver, WhatsApp transport (M4) |
| M4 | WhatsApp Business Platform integration — webhook, idempotency, messaging, retries | **🟡 Core shipped** — `/api/v1/webhooks/whatsapp`, `WhatsAppService`, real Meta bot test, inbound→AI reply; cart→order checkout still M5 |
| M5 | Orders lifecycle + events/notifications + Reverb real-time | Pending |
| M6 | Drivers/dispatch (WhatsApp-first) + tracking | Pending |
| M7 | Automation engine, coupons, delivery zones | Pending |
| M8 | Analytics/reporting, billing/subscriptions, audit dashboards | Pending |
| — | Frontend: repoint `web/lib/api.js` to `/api/v1`, retire demo-mode per page as real endpoints land | Ongoing |

**Working agreement:** build one milestone at a time; stop for review before starting the next.

---

## 8. Environment (resolved)

- **PHP 8.4+** + **Composer 2**. Production API runs in Docker on Render (`backend/Dockerfile`).
- **MAMP** installed; **MySQL 8.0.44** running. Laravel `.env` points at it
  (`127.0.0.1:3306`, db `neotalab`, root/root, socket `/Applications/MAMP/tmp/mysql/mysql.sock`).
- Tests run on in-memory SQLite via `phpunit.xml` (no MAMP needed to test).

No open blockers. **Next: M4 (WhatsApp integration).** Read this file first; then build one milestone and stop for review.
