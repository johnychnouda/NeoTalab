# NeoTalab Backend (Laravel 12)

Multi-tenant WhatsApp Commerce OS API. Generic across business types (restaurant, pharmacy,
retail, services, …). This is the production backend that supersedes the legacy Node reference
(`../reference/api`). See [`../PROJECT_MEMORY.md`](../PROJECT_MEMORY.md) for architecture decisions and the
milestone roadmap — **read it before starting new work.**

## Stack

- **Laravel 12** / PHP 8.4+ (built & tested on Homebrew PHP)
- **MySQL** via **MAMP** (phpMyAdmin for DB admin) — tests run on in-memory SQLite
- **Sanctum** bearer tokens · **spatie/laravel-permission** (RBAC) · **spatie/laravel-activitylog**

## Local setup

Prerequisites: Homebrew `php` + `composer`, and **MAMP** running with a `neotalab` database created
in phpMyAdmin (user/pass `root`/`root`).

```bash
cd backend
cp .env.example .env          # DB block is already set for MAMP MySQL
php artisan key:generate
composer install
php artisan migrate --seed    # or migrate:fresh --seed to rebuild
php artisan serve             # http://127.0.0.1:8000
```

Run the test suite (isolated, in-memory SQLite — MAMP not required):

```bash
php artisan test
./vendor/bin/pint             # code style
```

## Seeded accounts

| Role | Email | Password |
|------|-------|----------|
| Platform super-admin | `johnychnouda@gmail.com` | `neotalab2025` |

Merchants are created via the owner portal or `/join` onboarding — no demo merchant is seeded.

## API (v1)

Base path `/api/v1`. Auth is a Sanctum bearer token in `Authorization: Bearer <token>`.

| Method | Path | Access |
|--------|------|--------|
| POST | `/auth/register` | public — creates a merchant + owner, returns a token |
| POST | `/auth/login` | public |
| POST | `/auth/logout` | any authenticated user |
| GET | `/auth/me` | any authenticated user |
| GET | `/merchant` | merchant owner / admin / staff (own tenant) |
| PATCH | `/merchant` | merchant owner / admin |
| GET · POST | `/users` | merchant owner / admin (tenant-scoped) |
| PATCH · DELETE | `/users/{user}` | merchant owner / admin (tenant-scoped) |
| GET · POST | `/admin/merchants` | platform super-admin (cross-tenant) |
| GET | `/categories` · `/products` · `/products/{id}` | any merchant role (tenant-scoped) |
| POST · PATCH · DELETE | `/categories…` · `/products…` | merchant owner / admin |
| POST | `/products/{id}/variants` · `/products/{id}/modifier-groups` · `/modifier-groups/{id}/modifiers` | merchant owner / admin |
| PATCH · DELETE | `/variants/{id}` · `/modifier-groups/{id}` · `/modifiers/{id}` | merchant owner / admin |
| POST | `/conversations` | merchant owner / admin / staff (start or resume by phone) |
| GET | `/conversations/{id}` | merchant owner / admin / staff |
| POST | `/conversations/{id}/turns` | merchant owner / admin / staff — body: `{ "message": "…" }` |

`GET /products` is paginated and supports `q` (name/SKU search), `category_id`, `is_active`,
`is_available`, and `per_page` (max 100).

### AI conversation simulation (M3)

Set `AI_DRIVER=fake` (default, no API key) or `AI_DRIVER=openai` with `OPENAI_API_KEY` for live
structured output. Turn responses include `ai` (intent, confidence, entities, cart_actions,
recommended_action) and an updated `cart` with recalculated subtotal.

## Multi-tenancy (how isolation works)

- The tenant is the **`merchants`** row; every tenant-scoped table carries `merchant_id`.
- A `User` belongs to one merchant (or none, for platform admins). Roles are global
  (spatie *teams* intentionally unused).
- `ResolveTenant` middleware binds the caller's merchant into the `CurrentTenant` singleton;
  the `BelongsToTenant` trait's global `TenantScope` then constrains every query and auto-fills
  `merchant_id` on create. Platform admins bind no tenant and see across all merchants.

See `app/Support/CurrentTenant.php`, `app/Models/Concerns/BelongsToTenant.php`,
`app/Models/Scopes/TenantScope.php`, and `app/Http/Middleware/ResolveTenant.php`.
