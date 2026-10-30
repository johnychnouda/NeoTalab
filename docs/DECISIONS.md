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
| API | Laravel 13, PHP 8.5 (`backend/`) |
| Web | Next.js 16 / React 19, plain JSX, English / Arabic / French (`web/`) |
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
  links are sent to customers. Whish is paid as a transfer to the shop's Whish number (no payment page).
- **The shop accepts orders.** Staff numbers get a ticket with **Accept · usual time** and
  **Can't take it**. Shops with no staff accept from the shop phone by typing ✅ or ❌ in the
  customer's chat. Cash orders at shops with no reachable staff are accepted automatically.
- **Whish payments (no Whish API):** after the shop accepts, the customer gets the shop's Whish
  number, the exact amount and the order number to write in the Whish note. The customer taps
  "I paid"; the shop checks its own Whish account and taps Received or Not received. Nothing is
  marked paid by the customer. If the customer never taps "I paid" in the payment window, the order
  is cancelled. A payment the shop confirms goes ahead; one it refuses cancels the order.
- **Meta's 24-hour rule:** messages to staff who haven't written recently go as approved message
  templates (new order, customer needs a person, new booking, due today, refund needed).
- **Drivers** start and end shifts by sending "start" / "stop", get deliveries automatically
  (the driver free the longest), and tap Picked up / Delivered.
- **Delivery zones on Google Maps:** each zone is a circle or a shape drawn on the map with its fee,
  minimum and time (quick setup: rings around the shop). At checkout the customer sends a location pin
  first; the zone it falls in sets the fee (the smallest zone when they overlap), and a pin outside
  every zone is told the shop doesn't deliver there. Zones without a shape still work by name.
- **Time units per shop:** minutes (food), hours, or working days (retail, made to order).
- **Big menus:** above 80 items the AI only receives the items that match the conversation.
- **Menu import in lira:** each row is lira or dollars on its own (marked LL/LBP/ليرة, or 1,000 and
  up without a $), and the merchant can switch any row or the whole file in the preview. An item
  whose price comes out at 0.00 stops the import; 0.00 products can't be saved and are never orderable.
- **Opening hours:** a shop that never saved its hours is open 09:00–23:00 every day
  (`ShopHour::DEFAULT_OPEN/CLOSE`): the same hours the dashboard shows, for orders, bookings and the AI.
- **Customer photos** pause the assistant in that chat and alert the staff.
- **Countries:** sign-ups are open to Lebanon only (`App\Support\Countries::ENABLED` and
  `web/lib/countries.js`). Other countries are defined and can be switched on; a new shop gets
  the time zone and currency of its country.

## Platform owner

- New shops apply on `/join`; the platform owner approves them in the owner portal. There is no
  public self-registration.
- Free trial, then monthly or yearly subscription. Shops pay NeoTalab by Whish transfer to
  NeoTalab's Whish number with the shop's reference. The platform owner checks the NeoTalab Whish
  account and records the payment with its Whish reference: the only way a shop becomes paid. Each
  reference can be used once. Early payments add the new period to the end of the current one. Shops whose
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

## A new shop's login code (30 October 2026)
- The code goes only through WhatsApp, never by email (owner's rule). Meta rejected the first welcome and access templates,
  which carried the code inside a Utility text (no reason given). Now: a welcome without the code (`neotalab_welcome_v2`,
  `neotalab_access_v2`), then the code alone in Meta's Authentication format (`neotalab_code`, "Copy code" button).
- The code is the shop's first password. It lasts 24 hours (`users.activation_expires_at`); once used, one more hour to choose
  a password; choosing one ends the limit. A wrong password never reveals that a code existed or expired.
- If either template is not approved, nothing is sent and the owner sends the login details from their own WhatsApp (as before).
- Meta also rejected the code-free versions (`_v2`), so the code was not the reason (Meta gives none). `_v3`: the login is a URL button
  (`/backoffice/{{1}}`, the slug is the button value), the text holds only the shop name and email, the code stays in `neotalab_code`.
  `neotalab_daily_report` was never approved ("too many variables for its length"); its text is longer now.
- `_v3` was rejected within seconds too, so `_v4` drops every mention of codes, passwords and logging in from the welcome and access texts (a test checks this);
  the login page tells a first-time shop to use the 6-digit code from WhatsApp, valid 24 hours.
- Meta passed `neotalab_welcome_v4` into review but rejected `neotalab_access_v4` within seconds: "get back into your account" reads as account recovery. There is no
  separate access template any more; a regained login sends the welcome template and a fresh code.
- After a deploy, `php artisan templates:sync` submits the new templates to Meta; old rejected names are left behind.

## Before going live with real shops

Meta must approve the platform's Meta app before other businesses can connect their WhatsApp:
the business behind the platform completes **Business Verification**, then requests **Advanced
Access** for `whatsapp_business_messaging` and `whatsapp_business_management` in App Review.
