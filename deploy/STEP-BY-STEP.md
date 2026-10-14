# Deploying on Railway

**Chosen stack:** one [Railway](https://railway.app) project.

| Service | Folder / type | Job |
|---------|-----------------|-----|
| **mysql** | Railway MySQL | Database |
| **neotalab-api** | `backend/` | Laravel API + WhatsApp webhook |
| **neotalab-queue** | `backend/` | `docker/queue.sh` (3 workers) |
| **neotalab-web** | `web/` | Next.js dashboards |

Do **not** use Vercel, TiDB, AWS, or Render free (sleep). Use any local MySQL for development.

Keep API and worker **always on** (paid). Sleeping API misses WhatsApp.

## Step 1: Railway project + MySQL

1. Open [railway.app](https://railway.app) and sign in (GitHub login is fine).
2. **New project** → **Empty project**. Name it `NeoTalab`.
3. **Add service** → **Database** → **MySQL**.
4. Wait until it is running.

## Step 2: Laravel API (`backend/`)

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
AI_DRIVER=openai
OPENAI_API_KEY=<sk-… key>
WHATSAPP_GRAPH_VERSION=v26.0
```

The API runs on FrankenPHP, which answers many requests at once. Nothing to set for it; just don't
change the start command to `php artisan serve`.

Generate `APP_KEY` on your Mac: `cd backend && php artisan key:generate --show`

5. **Settings → Networking → Generate domain** (e.g. `neotalab-api.up.railway.app`).
6. Set `APP_URL=https://THAT-API-DOMAIN` (no trailing slash).
7. Deploy. Open `https://THAT-API-DOMAIN/up`, it should return 200.
8. After everything is deployed, open **Owner portal → Inbox → System**. The **Launch checklist** at the
   top shows each setting with ✓ or ✕ and what to set if it's missing.

If tables are empty, Railway → API → **one-off command** (or shell):

```bash
php artisan migrate --force --seed
```

The owner account comes from `OWNER_EMAIL` / `OWNER_PASSWORD`. If `OWNER_PASSWORD` is empty, the
seeder prints a generated password once; save it, then change it after first login.

## Step 3: Queue worker (same `backend/`)

1. **Add service** → same GitHub repo, root directory **`backend`** again.
2. Name it `neotalab-queue`.
3. **Copy variables from the API service** (or share the same variable set).
4. Set `RUN_MIGRATIONS=false`.
5. **Custom start command** (this replaces the API entrypoint on the queue service only):

```bash
docker/queue.sh
```

That script runs 3 workers side by side (`QUEUE_WORKERS`, default 3). Each worker stops a job after 75 seconds, which is shorter than the 150-second retry window, so a slow voice note is not handled twice. Do not replace this with a single `php artisan queue:work`.

(Do not run `php artisan serve` on this service.)

The queue also runs delayed jobs (late-order apologies, rating requests), so it must stay always on.

### Scheduler

The scheduler (owners' nightly reports, pruning expired logins) runs **inside the API service**, started
by `backend/docker/entrypoint.sh` in the background. No extra service is needed. Set `RUN_SCHEDULER=false`
on the API only if you ever move it to a dedicated service.

## Step 4: Next.js dashboards (`web/`)

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

## Step 5: Meta WhatsApp

[developers.facebook.com](https://developers.facebook.com) → your app:

| Field | Value |
|-------|--------|
| App domains | `YOUR-WEB-DOMAIN` (no `https://`) |
| Privacy policy | `https://YOUR-WEB-DOMAIN/privacy` |
| Terms | `https://YOUR-WEB-DOMAIN/terms` |
| OAuth redirect | `https://YOUR-WEB-DOMAIN/` |
| Allowed Domains for the JavaScript SDK (Facebook Login for Business → Settings; **Login with the JavaScript SDK** = Yes) | `https://YOUR-WEB-DOMAIN/`, without it "Connect WhatsApp" shows *JSSDK Unknown Host domain* |
| WhatsApp callback | `https://YOUR-API-DOMAIN/api/v1/webhooks/whatsapp` |

Verify token = `WHATSAPP_VERIFY_TOKEN` on the API. Subscribe to these webhook fields:
**messages**, **smb_message_echoes**, **history**, **smb_app_state_sync** (the last three are needed for
shops that keep the WhatsApp Business app on their number, "coexistence").

**Embedded Signup must use a v4 configuration** (Meta turns off v2 on **October 15, 2026**):
Facebook Login for Business → Configurations → **Create** → choose the **WhatsApp Embedded Signup**
variation and the Cloud API product → copy its **Configuration ID** into `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`
on the API service, and redeploy.

**Platform token:** use a permanent **System User** token (Business Settings → System users → Generate
token, expiry *Never*, permissions `whatsapp_business_messaging`, `whatsapp_business_management`,
`business_management`) in `WHATSAPP_PLATFORM_ACCESS_TOKEN` on API, queue and scheduler. The token on the
"API Setup" page expires after 24 hours.

**Owner messages (nightly report, subscription reminder)** are sent from each shop's own number to the
owner's personal number set in the dashboard. NeoTalab submits the two Utility templates it needs
(`neotalab_daily_report`, `neotalab_billing_reminder`) to the shop's WhatsApp account automatically when the
shop connects, and re-checks their approval every hour; nothing to create by hand. Those two messages
use the shop's own number.

**Welcome messages and problem alerts need a NeoTalab number.** Connect it in Owner portal → Settings →
WhatsApp. The `system_alert` WhatsApp alert is sent from that number (Step 7). Email alerts can still
go out without it; the WhatsApp alert cannot.

## Step 6: Smoke test

1. `https://YOUR-WEB-DOMAIN/owner` → log in (then change password).
2. Merchants list loads.
3. Connect WhatsApp on a merchant → send a test message.
4. Check **API** and **queue** logs on Railway.

## Step 7: Backups and error alerts (free)

**Problem alerts, built in (no extra account)**
Every warning or error the API logs (failed backup, WhatsApp or AI error…) is listed in
**Owner portal → Inbox → System**, with a count and details. Errors are also sent to the email and/or
WhatsApp number set in **Owner portal → Settings → Alerts** ("Send a test alert" shows what worked).

- **Email** goes through **Resend** (free plan): Railway blocks normal email (SMTP) ports on its
  Free/Hobby plans, so SMTP providers like Gmail time out. In resend.com: *Domains → Add domain* →
  `neotalab.com`, add the DNS records it shows at Namecheap, wait for "Verified"; *API Keys → Create*.
  Then add to the **API** and **queue** services and redeploy:

```env
MAIL_MAILER=resend
RESEND_API_KEY=<re_… key>
MAIL_FROM_ADDRESS=alerts@neotalab.com
MAIL_FROM_NAME=NeoTalab
```

- **WhatsApp** is sent from the platform number (Owner portal → Settings → WhatsApp) with an approved
  **Utility** template named `system_alert`, language English, body e.g.
  `NeoTalab problem: {{1}}. Open the owner portal → Inbox → System for details.`
  (another name/language: `ALERT_WHATSAPP_TEMPLATE`, `ALERT_WHATSAPP_LANGUAGE`).

**Error alerts, Sentry (optional, more detail)**
1. Create a free account at sentry.io → *Create project* → platform **Laravel**.
2. Copy the **DSN** and add `SENTRY_LARAVEL_DSN=<dsn>` to the **API** and **queue** services, then redeploy.

**Daily database backups, Cloudflare R2** (10 GB free)
1. Cloudflare dashboard → R2 → *Create bucket* (e.g. `neotalab-backups`).
2. R2 → *Manage API tokens* → create a token with **Object Read & Write** on that bucket.
3. On the **API** service add:

```env
BACKUP_S3_BUCKET=neotalab-backups
BACKUP_S3_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
BACKUP_S3_KEY=<access key id>
BACKUP_S3_SECRET=<secret access key>
BACKUP_S3_REGION=auto
```

4. Redeploy. Backups run every night at 03:00 (Beirut), are kept 30 days, and a failure shows in Inbox → System (and is sent to your alert email/WhatsApp).
   Run one by hand from the API service console: `php artisan backup:run --only-db --disable-notifications`.

**Live driving times, Google Maps (optional)**
1. console.cloud.google.com → create a project → *APIs & Services → Library* → enable **Routes API**.
2. *Credentials → Create credentials → API key*; restrict it to the Routes API.
3. Add `GOOGLE_MAPS_API_KEY=<key>` to the **API** and **queue** services, then redeploy.
4. Each shop pastes its Google Maps link (Share button) in its dashboard → Settings → Shop location.

Without the key, each delivery area's fixed time is used. Google is asked once per order; the first
10,000 a month are free, then about $5 per 1,000.

**Uptime monitor, UptimeRobot** (free): add HTTP monitors for `https://neotalab.com` and
`https://YOUR-API-DOMAIN/up` so you get an email/SMS if either goes down.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Dashboard cannot reach API | Fix `NEXT_PUBLIC_API_URL`, **rebuild** web |
| CORS on login | Web URL in `CORS_ALLOWED_ORIGINS` on API |
| Webhook fails | Callback is the **API** URL; token matches |
| DB errors | API and queue both reference the same MySQL |
| No WhatsApp replies | Queue service must be running; API must not sleep |
| Bot replies "fake" answers | `AI_DRIVER=openai` and `OPENAI_API_KEY` on API **and** queue |
| Not sure what is missing | Owner portal → Inbox → System → Launch checklist |

WhatsApp guide: [`docs/WHATSAPP.md`](../docs/WHATSAPP.md)
