# NeoTalab — Free HTTPS domains (release-ready until paid domain)

Use this when Meta rejects `localhost` in **App domains**. Meta needs a real top-level domain (`.com`, `.org`, `.app`, etc.) and **HTTPS**.

Recommended free stack:

| Layer | Service | Example URL | Cost |
|-------|---------|---------------|------|
| **Web** (Next.js) | [Vercel](https://vercel.com) | `https://neotalab.vercel.app` | Free |
| **API** (Laravel) | [Render](https://render.com) | `https://neotalab-api.onrender.com` | Free tier |
| **Database** | [TiDB Cloud Serverless](https://tidbcloud.com) (MySQL-compatible) | connection string | Free tier |
| **Queue worker** | Render background worker | same repo, `queue:work` | Free tier |

Replace `neotalab` with whatever subdomain Vercel/Render gives you.

---

## 1. Deploy the API (Render)

1. Push this repo to GitHub (if not already).
2. [Render Dashboard](https://dashboard.render.com) → **New → Blueprint** → connect repo → use root `render.yaml`.
3. Or **New → Web Service** → Docker → root directory `backend`.
4. Set environment variables from `backend/.env.production.example` (copy values from your local `.env` + WhatsApp keys).
5. After deploy, note the URL: `https://YOUR-SERVICE.onrender.com`.
6. Run migrations once (Render shell or deploy hook): `php artisan migrate --force --seed` (seed only for first deploy).

**Webhook URL for Meta:**

```text
https://YOUR-SERVICE.onrender.com/api/v1/webhooks/whatsapp
```

---

## 2. Deploy the web app (Vercel)

1. [Vercel](https://vercel.com) → **Add New Project** → import GitHub repo.
2. **Root directory:** `web`
3. **Environment variable:**

   ```env
   NEXT_PUBLIC_API_URL=https://YOUR-SERVICE.onrender.com/api/v1
   ```

4. Deploy. Note URL: `https://YOUR-PROJECT.vercel.app`.

---

## 3. Point backend at the public frontend

On Render (API service env):

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-SERVICE.onrender.com
FRONTEND_URL=https://YOUR-PROJECT.vercel.app
CORS_ALLOWED_ORIGINS=https://YOUR-PROJECT.vercel.app
```

Redeploy API after changing env.

---

## 4. Meta Developer Console (release settings)

**App settings → Basic**

| Field | Value |
|-------|--------|
| **App domains** | `YOUR-PROJECT.vercel.app` (no `https://`) |
| **Privacy policy URL** | `https://YOUR-PROJECT.vercel.app/privacy` |
| **Terms of Service URL** | `https://YOUR-PROJECT.vercel.app/terms` |
| **Site URL** | `https://YOUR-PROJECT.vercel.app/` |

**Facebook Login for Business → Settings**

| Field | Value |
|-------|--------|
| **Valid OAuth Redirect URIs** | `https://YOUR-PROJECT.vercel.app/` |

**WhatsApp → Configuration**

| Field | Value |
|-------|--------|
| **Callback URL** | `https://YOUR-SERVICE.onrender.com/api/v1/webhooks/whatsapp` |
| **Verify token** | same as `WHATSAPP_VERIFY_TOKEN` in API env |
| **Subscribe** | `messages` |

Save all changes.

---

## 5. Verify

1. Open `https://YOUR-PROJECT.vercel.app/owner` → log in.
2. **Settings → WhatsApp** → should show Connected (platform token already saved).
3. **Merchants → Bot → Connect WhatsApp** → complete Meta popup on the **Vercel** URL (not localhost).
4. **Resend Access** on a merchant → should send via Cloud API.

```bash
curl -H "Authorization: Bearer OWNER_TOKEN" \
  https://YOUR-SERVICE.onrender.com/api/v1/admin/whatsapp/embedded-signup/config
```

Expected: `"enabled": true`.

---

## 6. Queue worker (required for bot replies)

Render blueprint includes a worker service. If you deploy manually, add a **Background Worker** with:

```bash
php artisan queue:work --tries=3 --timeout=90
```

Same env vars as the web service.

---

## 7. When you buy a paid domain later

1. Add custom domain in Vercel → e.g. `app.neotalab.com`.
2. Add API subdomain → e.g. `api.neotalab.com` on Render.
3. Update Meta App domains, OAuth redirect, webhook callback.
4. Update `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `NEXT_PUBLIC_API_URL`.
5. No code changes required.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Meta “App domains” rejects localhost | Use Vercel `.vercel.app` URL |
| CORS error on login | Add Vercel URL to `CORS_ALLOWED_ORIGINS` on API |
| Embedded Signup stuck on Connecting | Complete all Meta screens; use HTTPS Vercel URL |
| Webhook verify fails | Callback URL must be public HTTPS API; token must match |
| API sleeps (Render free) | First request after idle takes ~30s; upgrade or use cron ping |

See also: [RELEASE-SETUP-IDE.md](./RELEASE-SETUP-IDE.md), [RELEASE-WHATSAPP.md](./RELEASE-WHATSAPP.md).
