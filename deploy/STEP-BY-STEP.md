# Deploy NeoTalab now — follow in order

Do each step before moving on. Total time ~45 minutes.

---

## Step 1 — GitHub (5 min)

1. Open https://github.com/new
2. Repository name: `NeoTalab`
3. **Private** recommended
4. Do **not** add README (repo already has files)
5. Create repository

In Terminal (replace `YOUR_GITHUB_USER`):

```bash
cd /Users/johnychnouda/Desktop/NeoTalab
git init
git add .
git commit -m "Initial commit — NeoTalab SaaS"
git branch -M main
git remote add origin https://github.com/YOUR_GITHUB_USER/NeoTalab.git
git push -u origin main
```

---

## Step 2 — TiDB free database (10 min)

1. Open https://tidbcloud.com → Sign up (free)
2. **Create Cluster** → **Serverless** → region closest to you
3. Create database: `neotalab`
4. **Connect** → choose **General** → copy:
   - Host
   - Port (usually `4000`)
   - User
   - Password
5. Open `deploy/secrets-for-render.env` on your Mac and replace:
   - `YOUR_TIDB_HOST`
   - `YOUR_TIDB_USER`
   - `YOUR_TIDB_PASSWORD`

---

## Step 3 — Render API (15 min)

1. Open https://dashboard.render.com → Sign up (GitHub login)
2. **New → Blueprint**
3. Connect your `NeoTalab` GitHub repo
4. Render detects `render.yaml` → **Apply**
5. Before deploy finishes, open service **neotalab-api** → **Environment**
6. Click **Add from .env** or paste all lines from `deploy/secrets-for-render.env`
7. Save → wait for deploy (first build ~5 min)
8. Copy your API URL (e.g. `https://neotalab-api.onrender.com`)
9. In Render **Shell** (or after deploy), run once:

```bash
php artisan migrate --force --seed
```

(Seeds owner login: `johnychnouda@gmail.com` / `neotalab2025`)

10. Re-save platform WhatsApp in owner portal after deploy, OR run locally against production API once logged in.

---

## Step 4 — Vercel web (10 min)

1. Open https://vercel.com → Sign up (GitHub login)
2. **Add New → Project** → import `NeoTalab`
3. **Root Directory:** click Edit → set to `web`
4. **Environment Variables:**
   ```
   NEXT_PUBLIC_API_URL=https://neotalab-api.onrender.com/api/v1
   ```
   (use your actual Render URL from step 3)
5. **Deploy**
6. Copy Vercel URL (e.g. `https://neotalab.vercel.app`)

7. Go back to **Render → neotalab-api → Environment** and update:
   ```
   FRONTEND_URL=https://neotalab.vercel.app
   CORS_ALLOWED_ORIGINS=https://neotalab.vercel.app
   ```
   Redeploy API.

---

## Step 5 — Meta app (5 min)

In https://developers.facebook.com → NeoTalab:

**App settings → Basic**

| Field | Value |
|-------|--------|
| App domains | `neotalab.vercel.app` |
| Privacy policy URL | `https://neotalab.vercel.app/privacy` |
| Terms of Service URL | `https://neotalab.vercel.app/terms` |

**Facebook Login for Business → Settings**

| Valid OAuth Redirect URIs |
|---|
| `https://neotalab.vercel.app/` |

**WhatsApp → Configuration**

| Callback URL |
|---|
| `https://neotalab-api.onrender.com/api/v1/webhooks/whatsapp` |

Verify token = same as `WHATSAPP_VERIFY_TOKEN` in Render env.

Subscribe to **messages**. Save.

---

## Step 6 — Test

1. Open `https://neotalab.vercel.app/owner`
2. Log in: `johnychnouda@gmail.com` / `neotalab2025`
3. **Settings → WhatsApp** → paste Phone Number ID + token → Save
4. **Merchants → foren al hara → Bot → Connect WhatsApp**
5. Complete Meta popup → should show **Connected**

---

## If Render URL or Vercel name differs

Replace `neotalab-api.onrender.com` and `neotalab.vercel.app` everywhere with your actual URLs.
