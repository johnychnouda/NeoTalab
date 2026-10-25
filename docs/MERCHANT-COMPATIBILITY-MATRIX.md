# Merchant compatibility matrix

Which kinds of shop NeoTalab can serve today, from the code and the tests (updated 25 October 2026, checkpoint 9).
"Listed products" is not the same as "supported business": each row says what is missing.

## What every shop gets (the generic system)

One location per shop · items with one set of sizes (`variants`) and option groups (`modifiers`, min/max) ·
a unit label per item (piece, kg, box…) · quantities 1–99, kg items in ¼ kg steps · stock per item, per size, and per size × option (e.g. M in Red)
· dated offers (% off) · delivery areas (circle or drawn shape, fee, minimum) or pickup · cash in $ / lira /
split, or Whish · shop accepts orders (staff buttons or ✅/❌ from the shop phone) · drivers on WhatsApp ·
bookings for items with a duration (a team: the person the customer names, or the first one free) ·
customer-chosen day and two-hour window, gift orders to another person (when switched on) · quotes for repair/custom work ·
nightly report · AI in Arabic, Arabizi, English, French. Kinds set the wording and suggestions only
(`App\Support\BusinessKind`): restaurant, grocery, retail, flowers, water, laundry, services, other (pharmacies and clinics removed 25 Oct).

Automated evidence for all kinds: `BusinessKindsTest` (every sign-up type takes a pickup order and runs
accept → ready → collected → cancel), lab `qa/s23_menus_by_type.py` (9 kinds, 144/144 checks, 23 Oct).
The whole backend suite (513 tests) passes on SQLite **and on MySQL 26.10** (25 Oct). Features added on 24–25 Oct
(weights, size × colour stock, team bookings, chosen times, quotes) also pass the real-AI lab suite `s25` (16/16 ×2, 25 Oct).

## Matrix

| # | Category | Status | Works today (evidence) | Missing / risk |
|---|---|---|---|---|
| 1 | Restaurants, cafés, bakeries, manakish | **FULLY SUPPORTED** | Menu, sizes, required/optional extras, delivery and pickup, staff, drivers, ratings, late alerts (`s05` 37/37, `s10`/`s11` real AI, `ChatMenuTest`, `CheckoutTest`, `AiActionSafetyTest`) | Scheduled orders for a later time; table/dine-in not offered (not needed for WhatsApp delivery) |
| 2 | Supermarkets, grocery, convenience | PARTIALLY | Units, stock, delivery areas, minimums, lira (`s23`); kg items in ¼ kg steps from buttons, typed weights or the AI (`WeightTest`) | No substitution rule ("if out of stock, replace?"); no weigh-then-charge (price is fixed at order time); large catalogues are cut to matching items for the AI (tested at 500 items, `s13`) |
| 3 | Pharmacies, health products | **REMOVED FROM THE PLATFORM** (25 Oct decision) | Sign-up refuses them; the code no longer offers them | — |
| 4 | General retail, specialty | PARTIALLY | Catalogue, options, "ready in N days" timing, stock per size and per option (`OptionStockTest`) | No bundles; no barcode/SKU import |
| 5 | Wholesale, bulk | NOT SUPPORTED | Only as an ordinary shop | No price tiers, customer-specific prices, per-item minimum or pack multiples; quantities stop at 99 per line |
| 6 | Electronics, appliances | PARTIALLY | Catalogue, sizes as models | No serial numbers, warranty or deposits; high-value orders rely on cash on delivery or Whish |
| 7 | Clothing, shoes (variant-heavy) | PARTIALLY (core works) | Size as variant + colour as an option group; stock per size × colour, sold-out combinations not offered, taken safely at checkout and given back on cancel (`OptionStockTest`) | Only one size dimension (no width/length for shoes or trousers); returns/exchanges not modelled |
| 8 | Salons, barbers, beauty | PARTIALLY (core works) | Services with minutes and price, free times, booking, reminder, cancel, done/no-show (`AppointmentTest`, `s07`); team: book the named person or the first one free (`ServiceProviderTest`) | No deposit; reschedule = cancel + book; per-person working hours not checked in the lab |
| 9 | Clinics, dentists, doctors | **REMOVED FROM THE PLATFORM** (25 Oct decision) | Sign-up refuses them | — |
| 10 | Repair shops, services | PARTIALLY | Quotes: customer describes the job, staff (or the shop phone) send a price, customer accepts once and it becomes an order (`QuoteTest` 12, incl. other shop's staff refused) | No deposit; no job stages beyond the order's (ready/collected); a quote order is always cash and pickup; photos of the job are not attached to the request |
| 11 | Florists, gifts, made-to-order | PARTIALLY (core works) | Options, notes; customer picks the day and a two-hour window; gift to another person with a card message (`ScheduledGiftOrderTest`) | No deposit; quotes can be switched on for custom cakes |
| 12 | Digital products, subscriptions | NOT SUPPORTED | — | No digital delivery, recurring orders or licences |
| 13 | Multi-branch, multi-location | NOT SUPPORTED | Workaround: one NeoTalab shop (and WhatsApp number) per branch | No shared catalogue, per-branch stock/prices or routing to the nearest branch |
| 14 | Water / gas delivery (found in code) | PARTIALLY | Gallons, cylinders, delivery areas (`s23`) | No refill subscriptions or bottle deposits |
| 15 | Laundry (found in code) | PARTIALLY | Price list per piece/kg, ready in days, quotes always on | Pickup-from-customer then return is not modelled; any message starting with "price…" (12+ letters) becomes a quote request instead of a price-list answer |

UNVERIFIED for all rows: anything that needs the **live** WhatsApp number or live Whish (Meta App Review
pending); these were tested with stand-ins only.

## Recommended order (demand × risk × effort)

1. Keep launch scope to restaurants/cafés/bakeries first, plus grocery, salons, florists and clothing shops that accept the gaps above.
3. Deposits (salons, made-to-order, repairs): medium; needs Whish partial payments.
4. Substitution rule for groceries: small-medium.
5. Wholesale tiers, multi-branch, digital: large; only with paying demand.
