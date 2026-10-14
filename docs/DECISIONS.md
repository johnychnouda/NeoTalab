# Product and technical decisions

The choices behind NeoTalab, and the rules the code follows. How it is built in detail:
[ARCHITECTURE.md](ARCHITECTURE.md).

## What NeoTalab is

A WhatsApp commerce platform for local businesses: WhatsApp is the shop's storefront, an AI
assistant takes orders and bookings in the chat, and web dashboards are used to set the shop up.
It is a multi-tenant SaaS and stays business-type agnostic (restaurants, bakeries, pharmacies,
groceries, retail, salons, clinics, services).

## Stack

| Part | Choice |
|------|--------|
| API | Laravel 12, PHP 8.4 (`backend/`) |
| Web | Next.js 15 / React 19, plain JSX, English / Arabic / French (`web/`) |
| Database | MySQL in production, in-memory SQLite for tests |
| Hosting | Railway: web, API (with the scheduler), queue worker, MySQL |
| WhatsApp | Meta WhatsApp Cloud API, shops connected with Embedded Signup (WhatsApp Business app kept: "coexistence") |
| AI | Provider-agnostic `ChatProvider`; OpenAI-compatible in production, a deterministic fake driver in tests |
| Email | Resend web API (Railway blocks SMTP ports on its smaller plans) |

## Architecture decisions

| Topic | Decision | Why |
|-------|----------|-----|
| Tenancy | One database, shared schema, `merchant_id` on every tenant table, enforced by a global scope | Scales to many shops without per-shop databases |
| Tenant | The `merchants` table is the tenant | One business = one merchant |
| Identity | People who log in are `users`, linked to a merchant (none for platform admins) | Several employees per shop, role-based access |
| Auth | Sanctum bearer tokens | Simple for the web dashboards |
| Roles | spatie/laravel-permission: platform super-admin, merchant owner, admin, staff | Per-shop permissions |
| Audit | spatie/laravel-activitylog | Who changed what |
| API | Versioned `/api/v1`; thin controllers → form requests → services → models → resources | Business logic stays in services |
| AI output | Structured JSON only, mapped to typed objects; free text from the model is never parsed | The AI picks items and next steps; prices, fees and availability always come from the server |
| IDs | UUID primary keys | Safe to expose, easy to merge |
| Foreign keys | Enforced in code, not in the database; child rows removed explicitly in services | Same migrations on SQLite and MySQL |

## How shops work

- **Everything happens in WhatsApp.** Customers order and book in the chat (menu lists, buttons,
  location pins, voice notes). The shop sees every conversation in its WhatsApp Business app.
  The dashboard is only for setup: menu, hours, delivery areas, team, payments, reports. No
  links are sent to customers, except the Whish payment page.
- **The shop accepts orders.** Staff numbers get a ticket with **Accept · usual time** and
  **Can't take it**. Shops with no staff accept from the shop phone by typing ✅ or ❌ in the
  customer's chat. Cash orders at shops with no reachable staff are accepted automatically.
- **Whish payments:** the payment link is sent only after the shop accepts, so a refused order
  is never paid. A payment counts only once Whish confirms the exact amount; checks run during the
  payment window and a late payment is flagged for refund.
- **Meta's 24-hour rule:** messages to staff who haven't written recently go as approved message
  templates (new order, customer needs a person, new booking, due today, refund needed).
- **Drivers** start and end shifts by sending "start" / "stop", get deliveries automatically
  (the driver free the longest), and tap Picked up / Delivered.
- **Time units per shop:** minutes (food), hours, or working days (retail, made to order).
- **Big menus:** above 80 items the AI only receives the items that match the conversation.
- **Customer photos** pause the assistant in that chat and alert the staff.
- **Countries:** sign-ups are open to Lebanon only (`App\Support\Countries::ENABLED` and
  `web/lib/countries.js`). Other countries are defined and can be switched on; a new shop gets
  the time zone and currency of its country.

## Platform owner

- New shops apply on `/join`; the platform owner approves them in the owner portal. There is no
  public self-registration.
- Free trial, then monthly or yearly subscription, recorded in the owner portal. Shops whose
  trial ends unpaid are suspended automatically.
- The platform has its own WhatsApp number (welcome messages and `system_alert` problem alerts),
  connected from Owner portal → Settings → WhatsApp. Nightly reports and subscription reminders
  are sent from each shop's own number and do not use this one.
- Problems the server logs are listed in Owner portal → Inbox → System and sent by email and
  WhatsApp to the addresses set in Settings → Alerts.

## Conventions

- Keep migrations database-agnostic (they run on SQLite in tests and MySQL in production).
- New tenant-scoped features come with isolation and role tests.
- Customer-facing WhatsApp texts live in `backend/lang/{en,ar,fr}/whatsapp.php`; dashboard texts
  in `web/lib/i18n*.js`. Every new text is added in all three languages.
- Tests never call outside services: every HTTP call is faked.

## Before going live with real shops

Meta must approve the platform's Meta app before other businesses can connect their WhatsApp:
the business behind the platform completes **Business Verification**, then requests **Advanced
Access** for `whatsapp_business_messaging` and `whatsapp_business_management` in App Review.
