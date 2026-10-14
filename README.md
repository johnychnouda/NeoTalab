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
