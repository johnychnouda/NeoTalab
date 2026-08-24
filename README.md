# NeoTalab

**AI WhatsApp Commerce OS** — a generic, multi-business, multi-tenant SaaS. WhatsApp is the
storefront, an AI agent handles sales, and dashboards manage the business.

---

## Production release

| Layer | Host | Path |
|-------|------|------|
| **Web** | [Vercel](https://vercel.com) | `web/` → `https://neo-talab.vercel.app` |
| **API + queue** | [Render](https://render.com) | `render.yaml` + `backend/Dockerfile` |
| **Database** | [TiDB Cloud Serverless](https://tidbcloud.com) | MySQL-compatible |

**Deploy checklist:** [`deploy/STEP-BY-STEP.md`](deploy/STEP-BY-STEP.md)

**Docs:** [`docs/README.md`](docs/README.md) · architecture: [`PROJECT_MEMORY.md`](PROJECT_MEMORY.md)

### One-time Vercel env

```env
NEXT_PUBLIC_API_URL=https://YOUR-RENDER-SERVICE.onrender.com/api/v1
```

Set Render secrets from [`deploy/secrets-for-render.env.example`](deploy/secrets-for-render.env.example)
(copy to `deploy/secrets-for-render.env` locally — gitignored).

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

Change the owner password after production login. Set `OWNER_EMAIL` / `OWNER_PASSWORD` in Render env
before seeding if you prefer different credentials.

---

## Repository layout

```
NeoTalab/
├── backend/                 # Laravel 12 API (production)
├── web/                     # Next.js 15 dashboards (production)
├── deploy/                  # Release runbook + Render secrets template
├── docs/                    # Release guides (WhatsApp, Meta, hosting)
├── render.yaml              # Render blueprint (API + queue worker)
└── PROJECT_MEMORY.md        # Architecture charter
```
