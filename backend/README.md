# NeoTalab Backend (Laravel 12)

Multi-tenant WhatsApp Commerce OS API. Generic across business types (restaurant, pharmacy,
retail, services, …). See [`../docs/ARCHITECTURE.md`](../docs/ARCHITECTURE.md) and
[`../docs/DECISIONS.md`](../docs/DECISIONS.md).

## Production (Railway)

Production runs on [Railway](https://railway.app). See [`../deploy/STEP-BY-STEP.md`](../deploy/STEP-BY-STEP.md).

1. Copy [`../deploy/secrets-for-railway.env.example`](../deploy/secrets-for-railway.env.example) to
   `../deploy/secrets-for-railway.env` (gitignored) and fill values (or reference Railway MySQL).
2. API root directory: `backend`. Queue: same image/folder, start command `docker/queue.sh`.

Migrations run on boot when `RUN_MIGRATIONS=true`.

**Health check:** `GET /up`

**Meta webhook:** `POST /api/v1/webhooks/whatsapp`

## Stack

- **Laravel 12** / PHP 8.4+
- **MySQL** (Railway in production; any local MySQL for development)
- **Sanctum** bearer tokens · **spatie/laravel-permission** · **spatie/laravel-activitylog**
- **Queue:** database driver (`docker/queue.sh` as a second Railway service, 3 workers)

## Local development

Prerequisites: `php` 8.4 + `composer`, and a local MySQL database named `neotalab`.

```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --port=8001 # http://127.0.0.1:8001
php artisan queue:work        # separate terminal — required for WhatsApp jobs
php artisan test              # SQLite in-memory
./vendor/bin/pint
```

## Seeded accounts

Seeding creates the platform super-admin from `OWNER_EMAIL` / `OWNER_PASSWORD`. If
`OWNER_PASSWORD` is not set, a random password is generated and printed once by the seeder;
copy it from the command output.

Merchants are created via the owner portal or `/join` onboarding.

## API (v1)

Base path `/api/v1`. Auth: `Authorization: Bearer <sanctum-token>`.

| Method | Path | Access |
|--------|------|--------|
| POST | `/auth/login` | public (5/min per email+IP) |
| POST | `/auth/logout` | authenticated |
| GET | `/auth/me` | authenticated |
| GET · PATCH | `/merchant` | merchant roles |
| GET · POST · PATCH · DELETE | `/users…` | merchant owner / admin |
| GET · POST | `/admin/merchants…` | platform super-admin |
| GET · POST · PATCH · DELETE | `/categories…` · `/products…` | merchant owner / admin |
| POST · GET | `/conversations…` | merchant owner / admin / staff |
| POST | `/webhooks/whatsapp` | Meta (unsigned; verify token / signature) |

`GET /products` supports `q`, `category_id`, `is_active`, `is_available`, `per_page` (max 100).

### AI conversation

`AI_DRIVER=fake` (default) or `AI_DRIVER=openai` with `OPENAI_API_KEY`.

## Multi-tenancy

- Tenant = **`merchants`** row; tenant-scoped tables carry `merchant_id`.
- `ResolveTenant` middleware + `BelongsToTenant` global scope enforce isolation.
- Platform admins bypass tenant scope.

See `app/Support/CurrentTenant.php`, `app/Models/Concerns/BelongsToTenant.php`,
`app/Http/Middleware/ResolveTenant.php`.
