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

## Messages to customers after 24 hours (31 October 2026)

WhatsApp allows plain text only for 24 hours after the customer's last message. Later, Meta accepts plain text and
drops it silently. Every customer message that can come that late (accepted, ready, on its way, delayed, cancelled,
booking reminder) goes through `CustomerMessenger`: inside the window the normal message, outside it the approved
template in the customer's language (English when there is none), and with no approved template nothing is sent and the
owner sees an alert in Inbox → System so the shop can write to the customer. A thank-you that is late is skipped without
an alert. Meta's failure reports for any message (shops and NeoTalab's own number) also become alerts.
The templates are named `neotalab_c_{event}_{en|ar|fr}`, are factual so Meta files them under Utility, and a shop pays
Meta for each one it sends outside the 24 hours.

## Every message that can arrive after 24 hours has a template, in three languages (31 October 2026)

NeoTalab has a template for each message that can reach someone who has not written to the sending number in the last
24 hours, in English, Arabic and French. The language is the receiver's: the shop's language for staff and owners, the
customer's for customers, the language of the join page for applicants, and a choice in Settings → Alerts for the
NeoTalab owner. When the version in their language is not approved, English goes out. Nothing goes as plain text there;
with no approved template the sender is told in Inbox → System (customers, staff) or the message is skipped (a late
thank-you, a late scheduled-order reminder).

- **From NeoTalab's number (33 templates, EN/AR/FR):** system_alert and neotalab_update (the wording around an alert;
  the alert text itself stays English), welcome, login code, paid notice, daily report, `neotalab_shop_paused` (trial
  over or payment overdue), `neotalab_whatsapp_down` (a shop's WhatsApp stopped working or expires within 7 days;
  `shops:check-whatsapp` runs daily at 10:20 Beirut and repeats every 3 days), `neotalab_shop_active` (the owner switched a
  shop back on; stopping one by hand sends `neotalab_shop_paused` too), `neotalab_request_received` and
  `neotalab_request_declined` (one message per kind per number per day, 30 an hour overall, because the form is public).
- **From a shop's number to its staff and owner (17, EN/AR/FR = 51):** new order, staff alert, new booking, order due,
  payment check, refund needed, billing reminder, customer cancelled, scheduled order due, booking cancelled, not
  delivered, Whish unpaid, low rating, quote request, quote declined, job card, deposit check. Button titles are translated; the button ids come from the code.
- **From a shop's number to customers (23 events, EN/AR/FR = 69):** accepted, ready, on its way, delayed, cancelled,
  refund, booking reminder, driver outside, delivered, delivered with the rating question, quote ready (with its two
  buttons), scheduled-order reminder, and the Whish messages: how to pay (with "I paid"), payment confirmed, payment
  not found, payment window closed (with "I paid"), booking cancelled by the shop, booking moved by the shop, job step, and the deposit messages (how to pay with "I paid", received, not found, window closed).
- A new shop starts in the language its owner used on the join page.
- Not added, on purpose: anything promotional (Marketing costs more and needs consent); drivers (they receive deliveries
  inside the window their own "start" opens).

## Bookings: the shop cancels or moves one, from WhatsApp (2 November 2026)

The new-booking ticket has three buttons: Done, No-show and Change. Change offers Move it, Cancel it and Keep it. Move it
sends a list of the next free times (this booking's day first, then the next 7 days, at most 10; the booking's own time is
not offered); the pick moves it (the old time is freed inside the same transaction, so a move to a nearby time is not
blocked by the booking itself) and the booking gets a new reminder; a reminder queued for the old time is dropped when it
fires (`SendAppointmentReminder::$dueAt`). Cancel it records `cancelled_by = shop`. The customer is told in their language
(the message inside the 24 hours, the approved template after). The team can also type "today" or "tomorrow" (or the
Arabic and French words) to get the day's bookings.

A shop with no staff is run by its owner: the booking ticket goes to the number the owner gave at sign-up
(`StaffMessenger::managers`), and that number may use the booking buttons and "today"/"tomorrow" only; anything else it
sends is an ordinary customer's message. A shop that has staff does not treat its owner number as staff. A customer's
tap on a team button changes nothing.

## Deposits and job steps (2 November 2026)

**Deposits (bookings only).** A shop sets a percent (5–100, 0 = off) in Settings → Orders. It applies when the shop takes
Whish (that is how the deposit is paid) and the service is not free. The booking then holds its time as `pending_deposit`
for the shop's Whish payment window (5–120 minutes, default 15): the customer gets the amount, the shop's Whish number and a
5-letter note to write, with an "I paid" button. "I paid" → `deposit_check`: the team (or a lone owner) gets a check with
Received / Not received. Received → `booked`, only now the ticket and the reminder go out, and the customer is told the
rest is paid at the shop. Not received, or the window ends (queue job `ExpireBookingDeposit`) → cancelled, the time is
free. A held time cannot be booked by someone else; a customer can still cancel a held booking. Cancelling a booking whose
deposit was paid tells the team a deposit is on it; the money is returned by the shop by hand (no refund automation).

**Job steps (orders).** A shop lists up to 8 steps in Settings → Orders ("Car received", "Work in progress"…). An accepted
order then gives the team a job card (Next stage / All stages / Ready) instead of the usual ticket; each move tells the
customer in their language (message inside 24 hours, template after) and shows in "Where's my order?". A shop with no staff
gives the card to its owner. Step names are the shop's own words and are not translated.

**Quote requests** are now recognised wherever "quote", "devis", "عرض سعر" and similar stand in the message; "price" and
"how much" still count only at the start.

## Delivery needs the location pin (5 November 2026)

A delivery order always has the customer's WhatsApp location pin. A typed address is never taken as where to deliver:
at the location step typed text only gets the location button again. What the customer types (or what the assistant
heard, "Sassine street, bldg Rizk, 3rd floor") is the note for the driver, asked right after the pin with a Skip button.
Why: the pin gives the zone, the fee and the Google driving time; a typed address gives none of that and was the only
reason for the delivery area's fixed time. `CheckoutService` refuses a delivery without a pin as well, so an assistant
that places an order by itself cannot get around it. Saved addresses without a pin (from before) are no longer offered.
Pickup is unchanged. The area's "Delivery time" stays as the backup when Google cannot answer.

## Delivery time: preparation first, arrival when it is on its way (5 November 2026)

For a delivery in minutes or hours, the customer is first told the preparation: the receipt says "Preparation about 25 min.
We will message you the arrival time when it is on its way"; the confirmation, the shop's accept message, "Where's my
order?" and a late update say "preparing now, ready around 10:25 AM". When the order goes out the arrival time is
recalculated from that moment (Google's driving time from the customer's pin, without live traffic) and the customer is
told "on its way with <driver> — arriving in about 15 min (around 10:45 AM)". A shop that delivers itself (no driver) gets
the same arrival message; before it got "on its way!" with no time. The receipt no longer needs the area's minutes, so it
costs no Google request; the one request per order is made when the shop accepts and is reused at dispatch. Pickup orders,
shops that count in days, appointments and orders for a chosen time are unchanged.

**Late.** `OrderTiming::dueAt()` is when the customer's latest promise runs out: for a delivery still at the shop, the time
it should leave (promised arrival minus the driving time); otherwise the promised time. `CheckLateOrder` runs `late_alert_mins`
(at most 5) after that, so a slow kitchen is reported at the time the customer was told, not 20 minutes later. The customer
gets "taking a little longer — new time: preparing now, ready around …" (the kitchen: leave in 10 minutes, then the drive) up to
3 times. When the order leaves, a new check is scheduled just after the new arrival time; if it is not delivered by then the
customer gets "a little delayed on the road — new arrival: arriving in about 10 min" (10 more minutes each time, up to the same 3
updates). After 3 updates nothing more is sent automatically. The driving time has no live traffic: a jam shows up as a delay
message after the arrival time, not before.

## Before going live with real shops

Meta must approve the platform's Meta app before other businesses can connect their WhatsApp:
the business behind the platform completes **Business Verification**, then requests **Advanced
Access** for `whatsapp_business_messaging` and `whatsapp_business_management` in App Review.
