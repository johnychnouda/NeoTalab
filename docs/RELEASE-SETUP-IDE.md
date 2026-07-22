# NeoTalab Release Setup — IDE Checklist

Follow these steps locally in Cursor/your IDE. No agent windows required.

---

## 1. Start services (two terminals)

**Terminal A — API + queue**
```bash
cd backend
php artisan serve --port=8001
```

**Terminal B — queue worker (required for inbound WhatsApp replies)**
```bash
cd backend
php artisan queue:work
```

**Terminal C — frontend**
```bash
cd web
npm run dev -- -p 3001
```

Confirm `web/.env.local`:
```env
NEXT_PUBLIC_API_URL=http://127.0.0.1:8001/api/v1
```

---

## 2. Meta Developer Console (one-time)

1. Go to [developers.facebook.com](https://developers.facebook.com) → your app → **WhatsApp**
2. **App ID** → copy to `WHATSAPP_META_APP_ID`
3. **App settings → Basic** → **App Secret** → `WHATSAPP_APP_SECRET`
4. **WhatsApp → Embedded Signup** → Create configuration → copy **Configuration ID** → `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`
5. **WhatsApp → Configuration**:
   - Callback URL: `https://YOUR-PUBLIC-API/api/v1/webhooks/whatsapp` (use ngrok locally for real Meta callbacks)
   - Verify token: pick a random string → same value in `WHATSAPP_VERIFY_TOKEN`
   - Subscribe to **messages**
6. **WhatsApp → API Setup** → copy a **System User / permanent token** for platform messages → `WHATSAPP_PLATFORM_ACCESS_TOKEN`
7. Copy **Phone number ID** for the platform number → Owner portal → **Settings → WhatsApp**

---

## 3. Backend `.env` (edit `backend/.env`)

Add or update:

```env
APP_URL=http://127.0.0.1:8001
FRONTEND_URL=http://localhost:3001

WHATSAPP_APP_SECRET=your_app_secret
WHATSAPP_META_APP_ID=your_app_id
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=your_embedded_config_id
WHATSAPP_VERIFY_TOKEN=your_random_verify_string
WHATSAPP_PLATFORM_ACCESS_TOKEN=EAAxxxxx
WHATSAPP_GRAPH_VERSION=v21.0
WHATSAPP_VERIFY_SIGNATURE=true

QUEUE_CONNECTION=database
```

After editing:
```bash
cd backend && php artisan config:clear
```

---

## 4. Verify config from the API

Log in to owner portal, then in browser devtools or curl:

```bash
curl -H "Authorization: Bearer YOUR_OWNER_TOKEN" \
  http://127.0.0.1:8001/api/v1/admin/whatsapp/embedded-signup/config
```

Expected:
```json
{ "enabled": true, "appId": "...", "configId": "...", "graphVersion": "v21.0" }
```

If `enabled` is `false`, check `WHATSAPP_META_APP_ID` and `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`.

---

## 5. Connect a merchant bot (Embedded Signup)

1. Open http://localhost:3001/owner
2. Log in as platform owner
3. **Merchants** → select shop (e.g. alMalikaWalAmir) → **Bot** tab
4. Click **Connect WhatsApp**
5. Complete Meta popup → bot status should become **Running**

No manual Phone Number ID entry.

---

## 6. Approve onboarding (welcome via platform API)

When you approve a join request:
- Backend sends welcome WhatsApp from the **platform number** (Settings → WhatsApp)
- Requires `WHATSAPP_PLATFORM_ACCESS_TOKEN` + platform Phone Number ID in owner settings
- If not configured, approval still succeeds; UI shows welcome error

Manual welcome from merchant Info tab also uses `POST /admin/merchants/{id}/welcome`.

---

## 7. Production checklist

| Item | Value |
|------|--------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://api.yourdomain.com` |
| `FRONTEND_URL` | `https://app.yourdomain.com` |
| CORS | allow frontend origin |
| Queue | `php artisan queue:work` via supervisor/systemd |
| Webhook | Meta callback → public HTTPS API URL |
| SSL | Required for Embedded Signup OAuth in production |

---

## 8. Run tests

```bash
cd backend && php artisan test
```

Embedded signup + welcome tests: `WhatsAppEmbeddedSignupTest`, `MerchantWelcomeTest`.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Embedded Signup button says disabled | Set Meta app ID + config ID in `.env`, `config:clear` |
| OAuth code exchange fails | App secret mismatch; complete signup within ~30s |
| Welcome not sent on approve | Platform token + Phone ID in Owner → Settings → WhatsApp |
| Bot active, no customer replies | `queue:work` not running |
| Webhook verify fails | `WHATSAPP_VERIFY_TOKEN` matches Meta dashboard |

See also: [RELEASE-WHATSAPP.md](./RELEASE-WHATSAPP.md)
