# NeoTalab

**AI WhatsApp Commerce OS** — a generic, multi-business, multi-tenant SaaS. WhatsApp is the
storefront, an AI agent handles sales, and dashboards manage the business.

---

## Production release

**Host:** [Railway](https://railway.app) (one project — web + API + queue + MySQL).

**Deploy checklist:** [`deploy/STEP-BY-STEP.md`](deploy/STEP-BY-STEP.md)

**Docs:** [`docs/README.md`](docs/README.md) · architecture: [`PROJECT_MEMORY.md`](PROJECT_MEMORY.md)

Set API secrets from [`deploy/secrets-for-railway.env.example`](deploy/secrets-for-railway.env.example)
(copy to `deploy/secrets-for-railway.env` locally — gitignored).

---

## Local development (optional)

**Prerequisites:** Homebrew PHP + Composer, Node.js, **MAMP** with a `neotalab` MySQL database.

### Backend

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve              # http://127.0.0.1:8000
php artisan queue:work         # separate terminal
php artisan test               # SQLite in-memory
```

### Frontend

```bash
cd web
cp .env.example .env.local
npm install
npm run dev                    # http://localhost:3000
```

| URL | What |
|-----|------|
| http://localhost:3000/owner | Platform owner portal |
| http://localhost:3000/backoffice | Merchant backoffice |

Details: [`backend/README.md`](backend/README.md)

---

## Seeded owner (first deploy / local dev only)

| Email | Password |
|-------|----------|
| `johnychnouda@gmail.com` | `neotalab2025` |

Change the owner password after production login. Set `OWNER_EMAIL` / `OWNER_PASSWORD` in Railway
API env before seeding if you prefer different credentials.

---

## Repository layout

```
NeoTalab/
├── backend/                 # Laravel 12 API (production)
├── web/                     # Next.js 15 dashboards (production)
├── deploy/                  # Railway runbook + secrets template
├── docs/                    # Release guides (WhatsApp, Meta, hosting)
└── PROJECT_MEMORY.md        # Architecture charter
```
