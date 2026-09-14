# Deploy NeoTalab — Railway (production)

**Chosen stack:** one [Railway](https://railway.app) project.

| Service | Folder / type | Job |
|---------|-----------------|-----|
| **mysql** | Railway MySQL | Database |
| **neotalab-api** | `backend/` | Laravel API + WhatsApp webhook |
| **neotalab-queue** | `backend/` | `php artisan queue:work` |
| **neotalab-web** | `web/` | Next.js dashboards |

Do **not** use Vercel, TiDB, AWS, or Render free (sleep). Local MAMP stays for coding on your Mac.

Keep API and worker **always on** (paid). Sleeping API misses WhatsApp.

---

## Step 1 — Railway project + MySQL

1. Open [railway.app](https://railway.app) and sign in (GitHub login is fine).
2. **New project** → **Empty project**. Name it `NeoTalab`.
3. **Add service** → **Database** → **MySQL**.
4. Wait until it is running.

---

## Step 2 — Laravel API (`backend/`)

1. **Add service** → **GitHub repo** → `NeoTalab`.
2. **Settings → Root directory:** `backend`
3. Railway will use `backend/Dockerfile`.
4. **Variables** → add everything from [`secrets-for-railway.env.example`](secrets-for-railway.env.example).

Link MySQL instead of typing host/password by hand:

- In the API service, **Variables → Add variable → Reference** (or “connect” MySQL).
- Map Railway MySQL vars to Laravel names, for example:
  - `DB_CONNECTION=mysql`
  - `DB_HOST` ← MySQL `MYSQLHOST` (or `MYSQL_HOST`)
  - `DB_PORT` ← `MYSQLPORT` (usually `3306`)
  - `DB_DATABASE` ← `MYSQLDATABASE`
  - `DB_USERNAME` ← `MYSQLUSER`
  - `DB_PASSWORD` ← `MYSQLPASSWORD`

Also set:

```env
APP_ENV=production
APP_DEBUG=false
LOG_CHANNEL=stderr
QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database
RUN_MIGRATIONS=true
```

Generate `APP_KEY` on your Mac: `cd backend && php artisan key:generate --show`

5. **Settings → Networking → Generate domain** (e.g. `neotalab-api.up.railway.app`).
6. Set `APP_URL=https://THAT-API-DOMAIN` (no trailing slash).
7. Deploy. Open `https://THAT-API-DOMAIN/up` — should return OK / 200.

If tables are empty, Railway → API → **one-off command** (or shell):

```bash
php artisan migrate --force --seed
```

Then **change the owner password** after first login. Do not keep the README password in production.

---

## Step 3 — Queue worker (same `backend/`)

1. **Add service** → same GitHub repo, root directory **`backend`** again.
2. Name it `neotalab-queue`.
3. **Copy variables from the API service** (or share the same variable set).
4. Set `RUN_MIGRATIONS=false`.
5. **Custom start command:**

```bash
php artisan queue:work --tries=3 --timeout=90
```

If the service uses Docker, set the start command in Railway to:

```bash
php artisan queue:work --tries=3 --timeout=90
```

(Do not run `php artisan serve` on this service.)

---

## Step 4 — Next.js dashboards (`web/`)

1. **Add service** → same GitHub repo.
2. **Root directory:** `web`
3. **Variables:**

```env
NEXT_PUBLIC_API_URL=https://YOUR-API-DOMAIN/api/v1
```

Use the real API Railway domain. Then **redeploy web** (this value is baked in at build).

4. **Generate domain** for web (e.g. `neotalab-web.up.railway.app`).
5. On the **API** service, set and redeploy:

```env
FRONTEND_URL=https://YOUR-WEB-DOMAIN
CORS_ALLOWED_ORIGINS=https://YOUR-WEB-DOMAIN
```

---

## Step 5 — Meta WhatsApp

[developers.facebook.com](https://developers.facebook.com) → your app:

| Field | Value |
|-------|--------|
| App domains | `YOUR-WEB-DOMAIN` (no `https://`) |
| Privacy policy | `https://YOUR-WEB-DOMAIN/privacy` |
| Terms | `https://YOUR-WEB-DOMAIN/terms` |
| OAuth redirect | `https://YOUR-WEB-DOMAIN/` |
| WhatsApp callback | `https://YOUR-API-DOMAIN/api/v1/webhooks/whatsapp` |

Verify token = `WHATSAPP_VERIFY_TOKEN` on the API. Subscribe to **messages**.

---

## Step 6 — Smoke test

1. `https://YOUR-WEB-DOMAIN/owner` → log in (then change password).
2. Merchants list loads.
3. Connect WhatsApp on a merchant → send a test message.
4. Check **API** and **queue** logs on Railway.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Dashboard cannot reach API | Fix `NEXT_PUBLIC_API_URL`, **rebuild** web |
| CORS on login | Web URL in `CORS_ALLOWED_ORIGINS` on API |
| Webhook fails | Callback is the **API** URL; token matches |
| DB errors | API and queue both reference the same MySQL |
| No WhatsApp replies | Queue service must be running; API must not sleep |

WhatsApp guide: [`docs/RELEASE-WHATSAPP.md`](../docs/RELEASE-WHATSAPP.md)
