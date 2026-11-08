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
- **Delivery areas are distance rows:** the owner types "up to 3 km: 1$, up to 6 km: 2$" (each row is a circle
  around the shop pin, with its fee, minimum and an estimated time); the map is only a preview. At checkout the
  customer sends a location pin first; the smallest circle it falls in sets the fee, and a pin outside every
  circle is told the shop doesn't deliver there. Drawing shapes was removed (see "Delivery areas as rows").
  Areas drawn earlier, and zones without a shape (by name), keep working.
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
  connected from Owner portal → Settings → WhatsApp. The nightly report is also sent from it (the
  approved `neotalab_daily_report` template), to the number the owner enters in the shop's Settings;
  that may be the shop's own WhatsApp number, since NeoTalab's number is a different sender. The bot
  ignores anything NeoTalab's number writes to a shop. Subscription reminders are sent from each
  shop's own number.
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
order?" and a late update say "preparing now, ready around 10:25 AM". When the order goes out, Google is asked again at that
moment (live traffic, from the shop to the customer's pin; the time saved at accept if Google cannot answer) and the customer is
told "on its way with <driver> — arriving in about 15 min (around 10:45 AM)". A shop that delivers itself (no driver) gets
the same arrival message; before it got "on its way!" with no time. The receipt no longer needs the area's minutes, so it
costs no Google request. Google is asked when the shop accepts (normal driving time, saved on the order for the plan and the
late check) and when the order leaves (live traffic, for the arrival time). Traffic-aware requests are priced higher than the
normal ones: check the current rate and free quota. Pickup orders, shops
that count in days, appointments and orders for a chosen time are unchanged.

**Late.** `OrderTiming::dueAt()` is when the customer's latest promise runs out: for a delivery still at the shop, the time
it should leave (promised arrival minus the driving time); otherwise the promised time. `CheckLateOrder` runs `late_alert_mins`
(at most 5) after that, so a slow kitchen is reported at the time the customer was told, not 20 minutes later. The customer
gets "taking a little longer — new time: preparing now, ready around …" (the kitchen: leave in 10 minutes, then the drive) up to
3 times. Once the order is on its way the customer keeps the arrival time they were given: no late check is scheduled when it leaves and
`CheckLateOrder` ignores orders already on their way (decided 5 November: no "delayed on the road" message). A driver's live
position is not available (WhatsApp's business service does not pass on live location), so the arrival time is the one asked of
Google when the order left the shop.

## Before going live with real shops

Meta must approve the platform's Meta app before other businesses can connect their WhatsApp:
the business behind the platform completes **Business Verification**, then requests **Advanced
Access** for `whatsapp_business_messaging` and `whatsapp_business_management` in App Review.

## Maps

- Every map (join form, shop location, delivery areas) uses Google's normal light map with shops and
  landmarks shown, so an owner can find their shop ("next to the pharmacy"). The earlier dark style
  hid them and was removed on 6 Nov 2026. Google's newer vector map needs a Map ID created in the
  Google Cloud console; not set up (the test browser could not load it).

## Ordering time, ratings and gifts (6 Nov 2026)

- **Time.** An order gets the shop's own time (preparation in minutes, hours or days by kind of shop). No
  question is asked. The order summary has Confirm / Another time / Cancel (it replaced "Edit order": changing
  items is typed, as before). "Another time" opens one list of the next times, earliest first, with "Another
  day" last; a customer can also write the time in plain words on the summary ("tomorrow at 6", "demain à
  18h", "بكرا الساعة 6 مساء") and the slot that holds it is taken. Slots: one hour for minutes shops, two for
  hours shops, a whole day for days shops (never before the shop's own days). Nothing earlier than the shop's
  own time is ever offered. A shop that is closed right now takes the order for an open time. After a time is
  chosen the middle button is "Soonest possible". `App\Support\DeliverySchedule`, `App\Support\TimeRequest`,
  `CheckoutFlow`; `ChooseTimeTest`, `TimeRequestTest`, lab `qa/s52_time_and_rating.py`.
- **Rating, always on.** After delivery or pickup the customer answers one-tap questions, Great / Okay / Bad
  (stored as 5 / 3 / 1): the order, the service and, for a delivery with a driver, the delivery person (named).
  `App\Services\RatingFlow`; button ids `rt:{order}:{question}:{value}`; columns `orders.rating`,
  `rating_service`, `rating_delivery`. A Bad answer alerts the staff in the shop's language and says which of
  the three (the delivery person by name). The reports (nightly, dashboard) count every answer and the
  dashboard shows the three averages. A number typed after a template message still counts as the order
  rating (the old way). The switch for ratings was removed.
- **Gifts removed** (the shop owner's decision): no "send it to someone else", recipient or card message. The
  `orders.recipient_*` and `gift_message` columns stay (old orders keep their data); nothing writes them.
  A flower shop delivers to the location pin the customer shares, with a note for the driver.

## Shop Settings page (6 Nov 2026)

- Tabs: Shop & account · Orders · Delivery areas · Opening hours · Payments · Reports & chats. The
  Account tab (only "Change password") now sits in the Shop tab.
- Money settings live together on Payments: cash in lira and the exchange rate, then Whish Pay. The
  Whish badge says "Number saved" / "Not set" (there is no connection to Whish, only the shop's number)
  and the card explains the steps.
- Rating, choosing a time and gifts are no longer settings (see below). What is left of the old
  "Payment & feedback" box is one card, "Repairs and custom work" (the quote switch and the job steps),
  shown by kind of shop (`business_kind`): hidden for restaurant, grocery and water shops. An option that is
  already switched on always stays visible, so it can be switched off.
- On a big screen the cards of a tab sit in two columns (one column below about 1000 px).

## Delivery areas as rows (6 Nov 2026)

- **One way only: distance rows.** Clicking points on a map to draw a shape was too hard for shop owners, so the
  Delivery areas tab is now a list of rows: up to N km, fee, minimum order, with "Add a distance" (up to 8) and one
  Save. Each row is saved as a circle centred on the shop pin; the map shows the circles as a preview.
- **Why circles:** owners think in "how far", customers understand it, and nothing can be drawn wrong. The weak spot
  is the sea or a mountain inside a circle; add a way to cut those out only if a real shop needs it.
- **Older areas stay:** shapes drawn before, circles centred somewhere else, and zones without a shape are shown
  below the rows with their name. Their prices can change and they can be removed, but they cannot be redrawn here.
  Saving a row never sends a new shape for them.
- **No backend change:** the API still accepts a circle or a polygon; only the dashboard stopped offering polygons.
- A row keeps a name the owner gave it while its distance is unchanged; otherwise it is named "Up to N km".
- **Drag the edge (added the same day):** on the map the edge of each circle can be dragged; the row's distance
  follows, rounded to 0.1 km and held between 0.5 and 60 km. The centre cannot be moved: it is the shop pin.
  Typing a distance moves the circle and never rounds what was typed. The map-only part is tested with a
  stand-in map (`e2e/zone-drag.spec.js`) because Google's key works only on neotalab.com; check the real drag on
  the live site after a change to `ZoneEditor`.


## Shops connect their own WhatsApp number (7 November)

- **The shop owner connects, not NeoTalab.** The shop dashboard's home shows a "Connect WhatsApp" step while the shop has
  no number. The owner signs in to Facebook, confirms the number with Meta's SMS code and adds a payment card on the
  last screen of the same window. The WhatsApp account is then created in the shop's own Meta business, so NeoTalab
  never needs access to it and never touches the card (shops pay Meta's message fees themselves).
- **Safe by design:** only the shop's owner or admin can do it (`settings/whatsapp/embedded-signup`); only for its own
  shop; only while it has no number (changing a connected number stays with the NeoTalab owner, with the button in
  Merchants → shop → WhatsApp); a number another shop already uses is refused. A failed signup writes its reason to
  Owner → Inbox → System.
- **The owner's Connect button was removed** from Merchants → shop → WhatsApp (the owner's decision: the shop connects
  and pays for itself). It now says the shop connects its own number. The server route behind it
  (`admin/merchants/{id}/whatsapp/embedded-signup`) is still there. If Meta blocks outside shop owners until NeoTalab's
  Access Verification (Tech Provider) is finished, or refuses a shop owner's Facebook account, the button can be put back.
- **Not tested against real Meta:** the Facebook window can only be tried by a real shop owner. The server side is
  tested with a stand-in Meta (`ShopWhatsAppSignupTest`).
- **Two limits fixed on the way:** the signup request used to run past PHP's 30 seconds because it also sent about a
  hundred templates to Meta (now a queued job, `SubmitShopTemplates`); the page gave up on the Facebook window after
  3 minutes (now 15), which lost the finish message.

## Each team member's own language, and translated notes (7 November)

- **Found with the first real shop:** the merchant and the driver read WhatsApp in Arabic but got English, because every
  team message used one language for the whole shop (`merchants.locale`, which the dashboard's EN/AR/FR switch also
  changes). The customer got Arabic because they typed Arabic: customers follow what they type (never the language of
  their WhatsApp app, which Meta does not tell us), and that stays.
- **Per person:** `drivers.locale` (en, ar, fr, or empty = the shop's language), chosen on the Team page. Order tickets,
  delivery jobs, cancellations, booking tickets, alerts and the replies to a driver or staff member use it
  (`Driver::languageFor`, `StaffMessenger::localeOf`). One ticket is built per recipient. Existing members keep the
  shop's language until someone picks.
- **Templates:** outside the 24-hour window a member gets the template in their language when it is approved, and the
  English one when it is not. Only five English templates had reached Meta for the first shop (the signup request ran
  out of time, see the previous entry); the Arabic and French ones follow with the hourly `templates:sync`.
- **Translated notes:** a customer's note on an item ("no onions") is kept in `order_items.note` and shown to a team
  member who reads another language as `Shawarma — بدون بصل (no onions)`: translation first, original beside it.
  `TextTranslator` (`OpenAiTextTranslator`, `FakeTextTranslator` in tests) sends only the note, never names, addresses
  or prices; an answer is kept for a week; a failure or a note already in that language leaves it as written. Never
  used for messages to customers. Setting: `AI_TRANSLATE_MODEL` (empty = the chat model), `AI_TRANSLATE_TIMEOUT`.
- **Not translated:** the typed delivery address and menu item names stay as written.

## No Reports page in the shop dashboard (7 November)

- **The owner's decision:** the shop dashboard no longer has a Reports page (sales, best sellers, ratings). The sidebar
  link is gone, and `/backoffice/<shop>/analytics` sends an old link or bookmark to the shop's home.
- **Kept:** the nightly report the shop gets on WhatsApp and its settings (Settings → Reports & chats), the server route
  `analytics/dashboard`, and all order data. Only the page was removed.

## Shop dashboard polish: five screens (7 November)

- **Pickup address follows the pin:** choosing a place on the map (search, tap, drag, "use my location") fills the
  pickup address line with Google's address for it (`LocationPicker` `onAddress`, `lib/pickupAddress.js`), unless the owner
  typed in that box during the same visit. The area line already followed the pin. The tap and drag lookup needs
  Google's Geocoding API on the Maps key; if it is off, the box simply stays as it is.
- **Payments:** Cash and lira (rate as "1 USD = [ ] LBP"), and Whish transfer with its three steps as a short numbered
  list instead of a paragraph; "Wait for “I paid” for [15] minutes".
- **Report & chats:** "Daily report" and "When you answer a chat yourself" (the ⏸ and ▶️ signs stay as written, they are the
  actual commands the shop sends).
- **Menu:** "1 item · 1 category"; Import, Export and Clear menu moved under a "More" button; Add item is the one main button.
- **Team:** an empty Staff section says so and offers "Add staff"; "1 delivery"; a member with their own language shows it.
- **Shop and account:** the number customers chat with is shown read-only with "Connected"; "Language for your team" is its
  own setting. The sidebar EN/AR/FR buttons now change only what the owner reads in the dashboard; before, they silently
  changed the language of every WhatsApp message to the team (the cause of the first shop's driver getting English).

## The assistant leads the chat (7 November)

- **The owner's decision:** customers talk to the assistant (`gpt-6-luna`); there is no menu list or item buttons to fall
  back on. A greeting or "show me the menu" gets the menu written out (`ChatMenu::textMenu`: ten categories at most, or the
  items when there is one category) and how to order. A first message that is a real question gets only its answer.
- **Sizes and required extras are asked in words** (`ConversationService::refusalReply`): when `CartService` refuses an add
  because a size or a required extra is missing, the reply is replaced with the choices and their prices, and that question is
  what the conversation remembers. Sold-out items and items that need the team's check are answered in words too.
- **Still taps, by decision:** Confirm, the payment choice and delivery or pickup (`CheckoutFlow`), "Where's my order?", the
  cancel Yes/No, and the staff and driver buttons. They protect money and orders.
- **When the assistant fails** the customer is told a team member will answer, and the staff get a handoff alert (`NotifyStaffOfHandoff`,
  reason `ai`) at most once in ten minutes per customer. The bot is not paused.
- **Removed:** the menu-list and item-button code in `ChatMenu` (`mn:` ids, `co:start:`, typed quantity), `BusinessKind::menuWord`,
  and the menu translation keys only it used. The approval message for a team-checked item now says "just ask me for it".
