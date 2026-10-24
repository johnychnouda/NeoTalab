# Merchant compatibility matrix

Which kinds of shop NeoTalab can serve today, from the code and the tests (24 October 2026).
"Listed products" is not the same as "supported business": each row says what is missing.

## What every shop gets (the generic system)

One location per shop · items with one set of sizes (`variants`) and option groups (`modifiers`, min/max) ·
a unit label per item (piece, kg, box…) · whole-number quantities 1–99 · stock count per item and, since D24, optionally per size
· dated offers (% off) · delivery areas (circle or drawn shape, fee, minimum) or pickup · cash in $ / lira /
split, or Whish · shop accepts orders (staff buttons or ✅/❌ from the shop phone) · drivers on WhatsApp ·
bookings for items with a duration (one shared calendar, `appointment_capacity` at the same time) ·
nightly report · AI in Arabic, Arabizi, English, French. Kinds set the wording and suggestions only
(`App\Support\BusinessKind`): restaurant, grocery, pharmacy, retail, flowers, water, laundry, services, other.

Automated evidence for all kinds: `BusinessKindsTest` (every sign-up type takes a pickup order and runs
accept → ready → collected → cancel), lab `qa/s23_menus_by_type.py` (9 kinds, 144/144 checks, 23 Oct).

## Matrix

| # | Category | Status | Works today (evidence) | Missing / risk |
|---|---|---|---|---|
| 1 | Restaurants, cafés, bakeries, manakish | **FULLY SUPPORTED** | Menu, sizes, required/optional extras, delivery and pickup, staff, drivers, ratings, late alerts (`s05` 37/37, `s10`/`s11` real AI, `ChatMenuTest`, `CheckoutTest`, `AiActionSafetyTest`) | Scheduled orders for a later time; table/dine-in not offered (not needed for WhatsApp delivery) |
| 2 | Supermarkets, grocery, convenience | PARTIALLY | Units, stock, delivery areas, minimums, lira (`s23`) | **No weights** (1.5 kg cannot be ordered: whole quantities only); no substitution rule ("if out of stock, replace?"); large catalogues are cut to matching items for the AI (tested at 500 items, `s13`) |
| 3 | Pharmacies, health products | PARTIALLY (regulated) | Products, boxes, photo of a prescription pauses the bot and alerts staff (`VoiceNoteTest`, `WhatsAppInboundHardeningTest`) | **No prescription check or prescription-only flag**: any listed item can be ordered by the AI. Safe use today: list only non-prescription items; prescriptions handled by the pharmacist in the chat. Needs a "requires prescription" flag that forces a hand-off, and a legal review |
| 4 | General retail, specialty | PARTIALLY | Catalogue, one option group (e.g. colour), "ready in N days" timing | Stock per size works (D24); stock per option (e.g. colour) does not |
| 5 | Wholesale, bulk | NOT SUPPORTED | Only as an ordinary shop | No price tiers, customer-specific prices, per-item minimum or pack multiples; quantities stop at 99 per line |
| 6 | Electronics, appliances | PARTIALLY | Catalogue, sizes as models | No serial numbers, warranty or deposits; high-value orders rely on cash on delivery or Whish |
| 7 | Clothing, shoes (variant-heavy) | PARTIALLY | Size as variant + colour as an option group | Stock per size works (D24, `AiActionSafetyTest`); **no size × colour stock**: a sold-out "M red" can only be shown by switching red off for every size |
| 8 | Salons, barbers, beauty | PARTIALLY (core works) | Services with minutes and price, free times, booking, reminder, cancel, done/no-show (`AppointmentTest` 14, `s07`) | Cannot choose a specific stylist (one calendar with a capacity); no deposit; reschedule = cancel + book |
| 9 | Clinics, dentists | PARTIALLY (regulated) | Same booking flow | No patient records by design; **health details may be typed in chat and sent to the AI provider**: needs consent text, a data-processing review and a policy not to ask for symptoms |
| 10 | Repair shops, services | PARTIALLY | "Laundry"/"other" kinds: price list, ready in days | No quote → approval → job status workflow |
| 11 | Florists, gifts, made-to-order | PARTIALLY | Options (colour, card), notes, ready in hours | No delivery date/time chosen by the customer; no recipient different from the buyer; no deposit |
| 12 | Digital products, subscriptions | NOT SUPPORTED | — | No digital delivery, recurring orders or licences |
| 13 | Multi-branch, multi-location | NOT SUPPORTED | Workaround: one NeoTalab shop (and WhatsApp number) per branch | No shared catalogue, per-branch stock/prices or routing to the nearest branch |
| 14 | Water / gas delivery (found in code) | PARTIALLY | Gallons, cylinders, delivery areas (`s23`) | No refill subscriptions or bottle deposits |
| 15 | Laundry (found in code) | PARTIALLY | Price list per piece/kg, ready in days | Pickup-from-customer then return is not modelled |

UNVERIFIED for all rows: anything that needs the **live** WhatsApp number or live Whish (Meta App Review
pending); these were tested with stand-ins only.

## Recommended order (demand × risk × effort)

1. Keep launch scope to restaurants/cafés/bakeries first, plus grocery and salons that accept the gaps above.
2. Pharmacy safeguard: "prescription only" flag → never added by the AI, always handed to the pharmacist (small, high risk reduction).
3. Decimal quantities for kg items (grocery, butcher, laundry): medium effort, touches cart, orders, reports.
4. Stock per size × option (clothing): medium-large (per size alone is done).
5. Customer-chosen delivery time (florists, bakeries' cakes): medium.
6. Stylist choice in bookings: medium.
7. Wholesale tiers, multi-branch, digital: large; only with paying demand.
