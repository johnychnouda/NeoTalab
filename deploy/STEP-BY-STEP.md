# Deploy NeoTalab — production release

Follow in order. Stack: **TiDB Cloud** (DB) → **Render** (API + queue) → **Vercel** (web) → **Meta** (WhatsApp).

---

## Step 1 — TiDB database (~10 min)

1. [tidbcloud.com](https://tidbcloud.com) → **Serverless** cluster
2. Create database: `neotalab`
3. **Connect → General** → copy host, port (`4000`), user, password
4. On your Mac:

```bash
cp deploy/secrets-for-render.env.example deploy/secrets-for-render.env
```

5. Edit `deploy/secrets-for-render.env` — fill `DB_*`, `APP_KEY` (`php artisan key:generate --show`), and all WhatsApp keys from Meta

---

## Step 2 — Render API (~15 min)

1. [dashboard.render.com](https://dashboard.render.com) → **New → Blueprint**
2. Connect GitHub repo **`NeoTalab`**
3. Apply **`render.yaml`** → creates **neotalab-api** + **neotalab-queue**
4. **neotalab-api → Environment** → paste contents of `deploy/secrets-for-render.env`
5. Wait for deploy → confirm `https://YOUR-SERVICE.onrender.com/up` returns 200
6. If migrations did not run automatically:

```bash
php artisan migrate --force --seed
```

(First deploy only — seeds platform owner; change password after login.)

7. Copy Render URL (e.g. `https://neotalab-api.onrender.com`)

---

## Step 3 — Vercel web (~10 min)

1. [vercel.com](https://vercel.com) → import **`NeoTalab`**
2. **Root directory:** `web`
3. **Environment variable** (value = URL only):

```env
NEXT_PUBLIC_API_URL=https://YOUR-RENDER-SERVICE.onrender.com/api/v1
```

4. Deploy → note URL (e.g. `https://neo-talab.vercel.app`)

5. **Render → neotalab-api → Environment** — update and redeploy:

```env
APP_URL=https://YOUR-RENDER-SERVICE.onrender.com
FRONTEND_URL=https://YOUR-VERCEL-PROJECT.vercel.app
CORS_ALLOWED_ORIGINS=https://YOUR-VERCEL-PROJECT.vercel.app
```

---

## Step 4 — Meta app (~5 min)

[developers.facebook.com](https://developers.facebook.com) → your app:

**App settings → Basic**

| Field | Value |
|-------|--------|
| App domains | `YOUR-VERCEL-PROJECT.vercel.app` |
| Privacy policy | `https://YOUR-VERCEL-PROJECT.vercel.app/privacy` |
| Terms | `https://YOUR-VERCEL-PROJECT.vercel.app/terms` |

**Facebook Login for Business → Settings**

| Valid OAuth Redirect URIs |
|---|
| `https://YOUR-VERCEL-PROJECT.vercel.app/` |

**WhatsApp → Configuration**

| Callback URL |
|---|
| `https://YOUR-RENDER-SERVICE.onrender.com/api/v1/webhooks/whatsapp` |

Verify token = `WHATSAPP_VERIFY_TOKEN` in Render env. Subscribe to **messages**. Save.

See also: [`docs/RELEASE-WHATSAPP.md`](../docs/RELEASE-WHATSAPP.md)

---

## Step 5 — Smoke test

1. `https://YOUR-VERCEL-PROJECT.vercel.app/owner` → log in
2. Merchants list loads (no API connection error)
3. **Settings → WhatsApp** → save Phone Number ID + access token
4. **Merchants → Bot → Connect WhatsApp** → complete Meta popup
5. Send a test WhatsApp message → check Render logs for webhook + queue job

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Vercel “Cannot reach API” | Check `NEXT_PUBLIC_API_URL`, redeploy Vercel, confirm Render `/up` is 200 |
| CORS on login | Add Vercel URL to `CORS_ALLOWED_ORIGINS` on Render |
| Webhook verify fails | Callback must be Render HTTPS URL; token must match env |
| First API request slow | Render free tier sleeps ~15 min idle; upgrade for always-on |
| DB connection error | Confirm TiDB creds + `MYSQL_ATTR_SSL_CA=/etc/ssl/certs/tidb-ca.pem` |

More detail: [`docs/RELEASE-FREE-DOMAIN.md`](../docs/RELEASE-FREE-DOMAIN.md)
