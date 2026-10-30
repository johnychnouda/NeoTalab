# NeoTalab merchant compatibility matrix (30 October 2026)

The full matrix, with evidence per row, is [MERCHANT-COMPATIBILITY-MATRIX.md](MERCHANT-COMPATIBILITY-MATRIX.md) (25 October,
checkpoint 9). Nothing in the product's shop types changed after that except how Whish payments work, so the statuses stand.
Today's lab runs that touch this: `s05` delivery journey 38/38, `s26` Whish transfer 21/21.

How the brief's categories map to what exists. The product has eight shop kinds (restaurant, grocery, retail, flowers, water,
laundry, services, other). It is not built per industry; everything runs on one generic engine.

| Brief category | Status | Note |
|---|---|---|
| Restaurants, cafés, bakeries, manakish, juice, ice cream, pizza, sushi, fast food, food truck | **Fully supported** | menu, sizes, extras, delivery or pickup, drivers |
| Grocery, supermarket, butcher, fish market, meal prep | Partially | kg in quarter-kilo steps; no substitution rule; no weigh-then-charge |
| Catering | Partially | works as ordinary orders; no quotes by headcount or deposits |
| Clothing, shoes, cosmetics, perfume, jewelry, gifts, toys, books, general retail | Partially | one size dimension; no returns |
| Electronics, appliances, furniture, auto parts, sports | Partially | no serial numbers, warranty or deposits |
| Flowers | Partially (core works) | chosen delivery day, gift orders |
| Salons, barbers, nails, spa, massage, makeup, skincare, tattoo | Partially (core works) | bookings with minutes, team, cancel; no deposit; reschedule is cancel then book |
| Pharmacy, medical, dental, optical, physiotherapy, vet, clinics | **Not appropriate: removed 25 Oct** | sign-up refuses them (regulated; owner decision) |
| Cleaning, plumbing, electrical, garage, laundry, home maintenance | Partially | quotes then order; no job stages, no deposit |
| Car rental, travel agency, real estate, event planning, photography | Requires development | no rental periods, listings or enquiries workflow |
| Education, tutoring, gym, fitness | Requires development | bookings exist; no classes, capacity or memberships |
| Wholesale, bulk | Not supported | no price tiers |
| Multi-branch chains | Not supported | one shop per branch as a workaround |
| Digital products, recurring orders | Not supported | |

Everything that needs the live WhatsApp number or a live Whish transfer is **unverified** for every row.
Recommended launch scope: restaurants, cafés and bakeries first; grocery, salons, florists and clothing shops only if they accept
the listed gaps.
