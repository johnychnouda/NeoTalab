# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> ⚠️ **Direction changed (2026-07): read [`PROJECT_MEMORY.md`](PROJECT_MEMORY.md) first.** The project
> is being rebuilt per the "Master Development Prompt" as a **generic, multi-business, multi-tenant
> SaaS** on a **Laravel 12** backend (`backend/`). The old restaurant-only locked scope (#1–78) and the
> Node API under `reference/api/` are **reference/history**, not the build target. `PROJECT_MEMORY.md`
> is the live source of truth for decisions, conventions, the API map, and the milestone roadmap.

## What this is

NeoTalab is an "AI WhatsApp Commerce OS". Originally a Lebanese-restaurant tool; now a generic
multi-business WhatsApp commerce SaaS. Surfaces:

- **`backend/`** — **Laravel 12 production backend (current build target).** Multi-tenant, Sanctum
  auth, spatie RBAC. **M1–M3 complete** — see `backend/README.md`.
- **`web/`** — Next.js 15 (App Router, JavaScript) frontend: owner portal (`/owner`) + merchant
  backoffice (`/backoffice`). **Kept and extended**; will consume the Laravel `/api/v1`.
- **`reference/api/`** — Node.js/Express **reference implementation** (superseded by `backend/`; useful for
  porting domain logic in later milestones).
- **`reference/database/schema.sql`** — original Postgres schema, **reference only** (Laravel migrations in
  `backend/database/` are the source of truth; DB is MySQL via MAMP).
- **`reference/static/`** — legacy static HTML previews (`demo/`, `owner/`, `backoffice/`, `join/`).

The old numbered scope in `docs/` (**PRODUCT-SCOPE.md**, **PRODUCT-SPEC.md**, etc.) is **historical
context** — helpful for domain behavior, but the Master Prompt supersedes it for scope. Previously
"removed" features (multi-tenant, gig drivers, coupons, non-restaurant businesses) are now **in scope**.
Confirm current direction in `PROJECT_MEMORY.md` before treating `docs/` as authoritative.

## Commands

### Backend (`backend/`) — production API

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve              # http://127.0.0.1:8000
php artisan test               # in-memory SQLite
./vendor/bin/pint              # code style
```

Requires **MAMP MySQL** for migrate/serve; tests do not.

### Web (`web/`)

```bash
cd web
npm install
npm run dev      # next dev -p 3000
npm run build    # next build
npm start        # next start -p 3000
```
- `/owner` — owner portal (merchants, billing, analytics, settings)
- `/backoffice` — merchant backoffice (orders, menu, drivers, customers, settings)
No test suite or lint script currently defined in `web/package.json`.

### Reference Node API (`reference/api/`) — legacy, optional

```bash
cd reference/api
cp .env.example .env
npm install
npm start                 # http://localhost:8787
npm run dev               # hot reload
npm run seed
```

### Database

**Source of truth:** Laravel migrations in `backend/database/` (MySQL via MAMP).  
**Reference only:** `reference/database/schema.sql` (Postgres) and `reference/database/migrations/*.sql`.

## Architecture (reference/api/)

Express app assembled in `reference/api/src/server.js`: `helmet` + `cors` + a global rate limiter, then routes mounted by prefix. Route modules under `reference/api/src/routes/` are thin — they call into `reference/api/src/services/` for domain logic and `reference/api/src/utils/response.js` for consistent JSON responses.

- **Auth** (`reference/api/src/middleware/auth.js`): JWT-based, three roles — `owner` (platform), `merchant`, `driver`. `requireAuth` validates the token; `requireOwner`/`requireMerchant` gate by role; `requireMerchantScope` lets an owner access any merchant's data but restricts a merchant to their own (`req.auth.merchantId` must match `req.params.merchantId`). Owner tokens carry an `iat` that's checked against `owners.tokens_valid_after` in the DB so an owner can force-invalidate all previously issued tokens (e.g. "sign out on all devices").
- **DB access** (`reference/api/src/db.js`): a shared `pg` `Pool`; use the exported `query(text, params)` for simple calls or `transaction(fn)` (wraps `BEGIN`/`COMMIT`/`ROLLBACK`) for multi-statement writes.
- **Services** encode the numbered feature behavior — map service → feature range via `docs/BUILD-PROMPT.md`'s "Key services" table, e.g. `DispatchService` (#41–44/46, auto-assign next available staff driver — never nearest/gig-style), `TrackingService` (#48–50, geofenced nearby/arrived), `WishPaymentService`-equivalent logic in `OrderService`/webhooks (#29–31, auto-confirm on payment match), `ShopHoursService` (#35), `OutageRecoveryService`-equivalent (#74). Order status transitions live in `reference/api/src/enums/OrderStatus.js` and must follow the full state machine in `docs/PRODUCT-SPEC.md` (never the removed simplified-status variant, #55).
- **Webhooks** (`reference/api/src/routes/webhooks.js`): WhatsApp inbound messages, rate-limited separately (`webhookLimiter`) and unauthenticated (verified by Meta signature instead of JWT). Idempotency is required — same `whatsapp_message_id` must never create two orders (#72).
- **Jobs** (`reference/api/src/jobs/renewalReminders.js`): started from `server.js` at boot (`startRenewalReminderJob()`), runs on an interval inside the process — not an external cron.

## Architecture (web/)

Next.js App Router, plain JavaScript (`.jsx`, no TypeScript — see `web/jsconfig.json` for the `@/*` path alias to the `web/` root). Two independently-authed sections under `web/app/`: `owner/` and `backoffice/`, each with its own `layout.jsx` that owns login state (JWT in `localStorage`, keyed `nt_owner_token` / `nt_merchant_token`) and renders a login screen when unauthenticated.

- **API client** (`web/lib/api.js`): `apiFetch(role, method, path, body)` is the only way pages should talk to the backend. Critically, **it silently falls back to `web/lib/demoData.js` whenever the fetch fails** (network error / API offline) — this "demo mode" is intentional product behavior (dashboards must work standalone), not a bug to remove. Token value `"demo_owner"` / `"demo"` marks a session as demo-only and is never sent as a real Bearer token.
- **i18n** (`web/lib/i18n.js`): client-side `LangProvider` context wrapping the whole app (`web/app/layout.jsx`), dictionary-based (EN/AR/FR), with RTL layout support for Arabic. Add new user-facing strings to the `I18N` dictionary rather than hardcoding text, and remember the merchant/customer/driver each see messages in their own language — never leak one role's language setting to another (#64/#65).
- **Shared UI** (`web/components/ui.jsx`, `web/components/owner/ui.jsx`): toast provider, modals, and other cross-page primitives — check here before adding a new component that might already exist.
- Owner-portal-specific composite components live under `web/components/owner/` (e.g. `MerchantPanel.jsx` for the merchant detail/impersonation view).

## Cross-cutting conventions

- Currency is Lebanese Lira (ل.ل.); timestamps/formatting helpers are in `web/lib/timezone.js`, `web/lib/revenue.js`, `web/lib/activity.js`, `web/lib/pillTones.js` — reuse these rather than re-deriving formatting.
- The default/seed owner account is `johnychnouda@gmail.com` (password `neotalab2025` per README) — treat as a dev-only credential, not something to change casually.
- When implementing a feature, identify its scope number(s) from `docs/PRODUCT-SCOPE.md` first — PR/commit descriptions and code comments in this repo often reference feature numbers (e.g. `// Full lifecycle #54`), and matching that convention makes intent traceable against the spec.
