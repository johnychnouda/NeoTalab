# NeoTalab Release Guide — WhatsApp Bot Configuration

This document explains how WhatsApp works in **release mode** and what you configure once vs per merchant.

---

## Two WhatsApp layers

| Layer | Where to configure | Used for |
|-------|-------------------|----------|
| **Platform WhatsApp** | Owner → Settings → WhatsApp | Billing reminders, platform messages to merchants |
| **Merchant bot** | Owner → Merchants → {shop} → Bot | Customer ordering on each shop's business number |

Both use **Meta WhatsApp Cloud API** via one NeoTalab Meta app.

---

## Release architecture (one Meta app, many shops)

```
                    ┌─────────────────────────┐
                    │   Meta App (NeoTalab)    │
                    │   ONE webhook URL        │
                    │   Embedded Signup        │
                    └───────────┬─────────────┘
                                │
              POST /api/v1/webhooks/whatsapp
                                │
                    ┌───────────▼─────────────┐
                    │   NeoTalab Laravel API   │
                    │   routes by phone_number_id │
                    └───────────┬─────────────┘
            ┌───────────────────┼───────────────────┐
            ▼                   ▼                   ▼
     Merchant A            Merchant B            Merchant C
   Phone Number ID        Phone Number ID        Phone Number ID
```

**You configure once:**
1. Meta Developer app + WhatsApp product
2. **Embedded Signup** configuration in Meta App Dashboard
3. Webhook URL + verify token + app secret in Meta dashboard
4. Backend env vars (see below)
5. Owner → Settings → WhatsApp (platform number for reminders)

**Per merchant (Embedded Signup — no manual IDs):**
1. Owner → Merchants → Bot tab → **Connect WhatsApp**
2. Meta popup walks through WABA + phone verification
3. NeoTalab stores Phone Number ID + token automatically
4. Merchant sets up menu/hours in backoffice

You do **not** register a separate webhook per merchant. One URL serves all tenants.

---

## Backend environment (release)

```env
WHATSAPP_APP_SECRET=your_app_secret
WHATSAPP_META_APP_ID=your_meta_app_id
WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID=your_embedded_signup_config_id
WHATSAPP_VERIFY_TOKEN=your_random_verify_string
WHATSAPP_PLATFORM_ACCESS_TOKEN=EAAxxxxx
WHATSAPP_GRAPH_VERSION=v21.0
```

| Variable | Purpose |
|----------|---------|
| `WHATSAPP_META_APP_ID` | Facebook Login + Embedded Signup |
| `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID` | From Meta → WhatsApp → Embedded Signup |
| `WHATSAPP_APP_SECRET` | OAuth code exchange + webhook signature |
| `WHATSAPP_VERIFY_TOKEN` | Webhook GET verification |
| `WHATSAPP_PLATFORM_ACCESS_TOKEN` | Platform billing/welcome messages only |

---

## Step-by-step: platform setup (once)

### A. Meta Developer app

1. [Meta for Developers](https://developers.facebook.com) → Create app → Add **WhatsApp**
2. WhatsApp → **Embedded Signup** → Create configuration → copy **Configuration ID**
3. Copy **App ID** and **App Secret**
4. WhatsApp → Configuration:
   - **Callback URL:** `https://YOUR-API-DOMAIN/api/v1/webhooks/whatsapp`
   - **Verify token:** same as `WHATSAPP_VERIFY_TOKEN`
   - Subscribe to `messages`
5. Set backend `.env` vars above
6. Owner portal → **Settings → WhatsApp** (platform Phone Number ID for billing reminders)

### B. Per merchant (Embedded Signup)

1. Owner → Merchants → click shop → **Bot** tab
2. Click **Connect WhatsApp**
3. Complete Meta Embedded Signup (business verification, phone number)
4. NeoTalab exchanges the OAuth code, subscribes the WABA, tests the connection
5. Bot status should show **Running**

To switch a banned number: Bot tab error actions → **Switch Number** → Embedded Signup again.

### C. Go live

1. Customer sends WhatsApp to merchant business number
2. Meta POSTs to NeoTalab webhook
3. Backend finds merchant by `phone_number_id`
4. AI (`ConversationService`) replies via Cloud API
5. Orders appear in merchant backoffice (cart flow; full order checkout in M5)

---

## API endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/api/v1/admin/whatsapp/embedded-signup/config` | App ID + config ID for frontend SDK |
| `POST` | `/api/v1/admin/merchants/{id}/whatsapp/embedded-signup` | Complete signup (`code`, `phoneNumberId`, `wabaId`) |
| `GET/POST` | `/api/v1/webhooks/whatsapp` | Meta webhook (public) |
| `POST` | `/api/v1/admin/merchants/{id}/bot/test` | Health check against Meta Graph API |

Legacy manual bot PATCH (`/bot`) remains for emergency ops but is not exposed in the owner UI.

---

## Queue worker

Inbound WhatsApp messages are processed asynchronously:

```bash
cd backend && php artisan queue:work
```

Without a worker, webhooks are accepted but replies are delayed until the queue runs.

---

## Troubleshooting

| Symptom | Check |
|---------|-------|
| "Embedded Signup is disabled" | `WHATSAPP_META_APP_ID` + `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID` in backend `.env` |
| OAuth code exchange fails | App secret matches Meta dashboard; code used within ~30 seconds |
| Bot active but no replies | Queue worker running; webhook subscribed in Meta |
| Wrong merchant receives message | Each shop must have unique Phone Number ID stored after signup |

---

## Local development

Embedded Signup requires a **public HTTPS callback** for Meta OAuth in production. For local bot testing without Embedded Signup, use the legacy admin API PATCH `/admin/merchants/{id}/bot` with test credentials and `Http::fake()` in PHPUnit.
