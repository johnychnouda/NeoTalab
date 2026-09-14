# NeoTalab Backend (Laravel 12)

Multi-tenant WhatsApp Commerce OS API. Generic across business types (restaurant, pharmacy,
retail, services, …). See [`../PROJECT_MEMORY.md`](../PROJECT_MEMORY.md) for architecture and
the API map.

## Production (Railway)

Production runs on [Railway](https://railway.app). See [`../deploy/STEP-BY-STEP.md`](../deploy/STEP-BY-STEP.md).

1. Copy [`../deploy/secrets-for-railway.env.example`](../deploy/secrets-for-railway.env.example) to
   `../deploy/secrets-for-railway.env` (gitignored) and fill values (or reference Railway MySQL).
2. API root directory: `backend`. Queue: same image/folder, start `php artisan queue:work`.

Migrations run on boot when `RUN_MIGRATIONS=true`.

**Health check:** `GET /up`

**Meta webhook:** `POST /api/v1/webhooks/whatsapp`

## Stack

- **Laravel 12** / PHP 8.4+
- **MySQL** (Railway in production; MAMP locally)
- **Sanctum** bearer tokens · **spatie/laravel-permission** · **spatie/laravel-activitylog**
- **Queue:** database driver (`queue:work` as a second Railway service)

## Local development

Prerequisites: Homebrew `php` + `composer`, **MAMP** with a `neotalab` database (`root`/`root`).

```bash
cd backend
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate --seed
php artisan serve             # http://127.0.0.1:8000
php artisan queue:work        # separate terminal — required for WhatsApp jobs
php artisan test              # SQLite in-memory
./vendor/bin/pint
```

## Seeded accounts (dev / first deploy only)

| Role | Email | Password |
|------|-------|----------|
| Platform super-admin | `johnychnouda@gmail.com` | `neotalab2025` |

Merchants are created via the owner portal or `/join` onboarding.

## API (v1)

Base path `/api/v1`. Auth: `Authorization: Bearer <sanctum-token>`.

| Method | Path | Access |
|--------|------|--------|
| POST | `/auth/register` | public |
| POST | `/auth/login` | public |
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
