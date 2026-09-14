# NeoTalab — HTTPS domains (Meta)

Meta rejects `localhost`. You need a public HTTPS URL.

**Production is Railway** — full steps: [`deploy/STEP-BY-STEP.md`](../deploy/STEP-BY-STEP.md).

Railway gives you domains like `something.up.railway.app` with HTTPS. Use:

| Meta field | Service |
|------------|---------|
| App domains, OAuth, privacy, terms | **neotalab-web** Railway domain |
| WhatsApp callback | **neotalab-api** `/api/v1/webhooks/whatsapp` |

Keep the API and queue **always on**. Do not use a sleeping free host for WhatsApp.

When you buy a real domain later, attach it in Railway and update Meta + `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `NEXT_PUBLIC_API_URL`.
