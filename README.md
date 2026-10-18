# NeoTalab

WhatsApp commerce for local shops. Customers order, book and pay inside a WhatsApp chat, an AI
assistant takes the order, and the shop runs everything from a web backoffice. Multi-tenant: one
platform, many shops (restaurants, pharmacies, retail, services).

- `backend/` Laravel 12 API and queue workers
- `web/` Next.js 16 (owner portal, shop backoffice, public site)
- `deploy/` Railway setup and secrets template
- `docs/` architecture, decisions, WhatsApp setup, local testing

## Production

Runs on [Railway](https://railway.app): web, API, queue and MySQL in one project.
Setup steps are in [deploy/STEP-BY-STEP.md](deploy/STEP-BY-STEP.md). API secrets go in
`deploy/secrets-for-railway.env` (copy the `.example` file; the real one is gitignored).

More reading: [architecture](docs/ARCHITECTURE.md), [decisions](docs/DECISIONS.md),
[WhatsApp](docs/WHATSAPP.md).

## Services to keep an eye on

**Weekly**

| Site | What it's for | What to watch |
|------|---------------|---------------|
| [neotalab.com/owner](https://neotalab.com/owner) | Owner portal | New shop requests, Inbox → System problems, who has paid |
| [Railway](https://railway.com) | Website, API, queue, MySQL | All 4 services online, monthly bill |
| [OpenAI](https://platform.openai.com) | The bot's AI | Credit balance: if it runs out the bot stops understanding customers. Keep auto-recharge on with a monthly limit |
| [Meta Business](https://business.facebook.com) → WhatsApp Manager | WhatsApp for every shop and the platform number | Number quality (green), templates approved, payment method for messages |

**Monthly**

| Site | What it's for | What to watch |
|------|---------------|---------------|
| [Meta for Developers](https://developers.facebook.com) | The Meta app (Connect WhatsApp, webhooks) | App Review / Business Verification status, warnings |
| [Google Cloud](https://console.cloud.google.com) | Google Maps driving times (Routes API) | Bill, billing account active |
| [Sentry](https://sentry.io) | Error details | Only when something breaks |
| [Cloudflare](https://dash.cloudflare.com) → R2 | Nightly database backups | A new backup file each night |
| [Resend](https://resend.com) | Alert emails | Domain still verified |

**Yearly, or when changing something**

| Site | What it's for | What to watch |
|------|---------------|---------------|
| [Namecheap](https://www.namecheap.com) | The neotalab.com domain | Auto-renew on: an expired domain takes everything offline |
| [GitHub](https://github.com) | The code | Nothing day to day; Railway deploys every push to `main` |

Good to add: [UptimeRobot](https://uptimerobot.com) (free) for an email or SMS when neotalab.com or
the API goes down.

Keep 2-step login on every account above, the passwords in a password manager, and a card that
won't expire soon on Railway, OpenAI, Meta and Google: a failed payment can stop the bot.

## Running locally

You need PHP 8.4 with Composer, Node.js, and a MySQL database called `neotalab`.

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8001
php artisan queue:work   # second terminal
php artisan test
```

```bash
cd web
cp .env.example .env.local
npm install
npm run dev              # http://localhost:3001
```

Owner portal: http://localhost:3001/owner. Shop backoffice: http://localhost:3001/backoffice.

The seeder creates the platform owner from `OWNER_EMAIL` and `OWNER_PASSWORD`. Without a
password it generates one and prints it once.

More backend notes in [backend/README.md](backend/README.md).
