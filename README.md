# NeoTalab

**AI WhatsApp Commerce OS** — a generic, multi-business, multi-tenant SaaS. WhatsApp is the
storefront, an AI agent handles sales, and dashboards manage the business.

> **Start here:** [`PROJECT_MEMORY.md`](PROJECT_MEMORY.md) — architecture, API map, milestone roadmap.

---

## What's active

| Layer | Path | Stack | Status |
|-------|------|-------|--------|
| **Backend** | [`backend/`](backend/) | Laravel 12 · MySQL (MAMP) · Sanctum · spatie RBAC | M1–M3 complete (`/api/v1`) |
| **Frontend** | [`web/`](web/) | Next.js 15 · EN/AR/FR | Owner portal + merchant backoffice (wired to Laravel `/api/v1`) |

Legacy Node/Postgres code and static HTML previews live under [`reference/`](reference/) — **not**
the build target.

---

## Quick start (native — no Docker)

**Prerequisites:** Homebrew PHP + Composer, Node.js, **MAMP** running with a `neotalab` MySQL database
(`root` / `root`).

### Backend

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve          # http://127.0.0.1:8000
php artisan test           # SQLite in-memory — MAMP not required
```

Details: [`backend/README.md`](backend/README.md)

### Frontend

```bash
cd web
npm install
npm run dev                # http://localhost:3000
```

| URL | What |
|-----|------|
| http://localhost:3000/owner | Platform owner portal |
| http://localhost:3000/backoffice | Merchant backoffice |

Point `web/lib/api.js` at `http://127.0.0.1:8000/api/v1` as Laravel endpoints land (ongoing).

---

## Seeded accounts (backend)

| Role | Email | Password |
|------|-------|----------|
| Platform super-admin | `johnychnouda@gmail.com` | `neotalab2025` |

Create merchants via the owner portal or the public `/join` page — no demo data is seeded.

---

## Repository layout

```
NeoTalab/
├── backend/           # Laravel API (production)
├── web/               # Next.js dashboards (production)
├── assets/            # Shared brand logos
├── docs/              # Historical product spec (#1–78)
├── reference/         # Legacy Node API, Postgres schema, static previews
├── PROJECT_MEMORY.md  # Live charter — read before any work
└── CLAUDE.md          # Agent/dev orientation
```

---

## API (current — Laravel `/api/v1`)

See the full map in [`PROJECT_MEMORY.md`](PROJECT_MEMORY.md) and [`backend/README.md`](backend/README.md).

Highlights: auth · tenant profile · users · admin merchants · catalog · AI conversation simulation.
