# NeoTalab — Product scope (locked)

> Official build scope. Reference feature **#** when discussing add/skip.  
> Full behavior: **`PRODUCT-SPEC.md`**. Build order: bottom of this file.

**Positioning:** AI WhatsApp Commerce OS — not Toters/Talabat. Replace chaotic WhatsApp ordering, owner stress, missed orders, manual coordination for Lebanese shops.

**Target:** Merchants subscribe for automation + insights; customers get professional WhatsApp ordering + delivery.

---

## Feature index (1–78)

| # | Feature | Status |
|---|---------|--------|
| 1 | WhatsApp-first operations | Active |
| 2 | Backoffice = setup only | Active |
| 3 | AI order draft (multilingual + voice) | Active |
| 4 | Interactive WhatsApp buttons | Active |
| 5 | Busy mode (auto cash, auto driver) | Active |
| 6 | Manual mode | Active |
| 7 | Positioning: AI WhatsApp Commerce OS | Active |
| 8 | Basic menu (products, prices) | Active |
| 9 | Sold out flag | Active |
| 10 | Stock quantity + low stock | V2 |
| 11 | Full menu engine (categories, variants, combos) | Active |
| 12 | Modifier engine (groups, rules, pricing) | Active |
| 13 | Cart engine (edit, recalc fees) | Active |
| 14 | Partial availability / substitutions | V2 |
| 15 | Time-based menus (breakfast, Ramadan, etc.) | **Removed** |
| 16 | Structured order notes | Active |
| 17 | Customer profile + block list | Active |
| 18 | Saved delivery addresses | Active |
| 19 | Reorder last order | **Removed** |
| 20 | Favorites | V2 |
| 21 | Smart AI memory | V2 |
| 22 | WhatsApp location pin | Active |
| 23 | Building name (required) | Active |
| 24 | Additional info (gate, directions) | Active |
| 25 | Zones + fee + minimum | Active |
| 26 | Advanced fee rules (free over X, surge) | **Removed** |
| 27 | Outside zone → suggest pickup | Active |
| 28 | Cash | Active |
| 29 | Wish + auto-confirm | Active |
| 30 | Wish wrong amount | Active |
| 31 | Wish timeout | Active |
| 32 | Cash reconciliation | Active |
| 33 | OMT / cards | **Removed** |
| 34 | Fraud / spam protection | V2 |
| 35 | Opening hours + closed reply | Active |
| 36 | Scheduled orders | Active |
| 37 | Prep time engine (per item + rush) | Active |
| 38 | Kitchen delay (+15 / +30) | Active |
| 39 | Rush queue / throttle | Active |
| 40 | In-house staff drivers | Active |
| 41 | Auto-assign driver (next available staff) | Active |
| 42 | Manual assign driver | Active |
| 43 | One active delivery per driver | Active |
| 44 | Driver states (on duty / off / break / paused) | Active |
| 45 | Nearest-driver dispatch (gig-style) | **Removed** — staff pool only, not external/nearest gig logic |
| 46 | Driver timeout → reassign | Active |
| 47 | Driver 2 taps (left store, delivered) | Active |
| 48 | Live tracking + geofencing (nearby, arrived) | Active |
| 49 | Simple ETA text (fallback) | Active |
| 50 | Dynamic ETA (prep + distance + load) | Active |
| 51 | Delivery failure / incident flows | Active |
| 52 | Delivery proof (photo / OTP) | **Removed** |
| 53 | Driver shifts + cash collected stats | Active |
| 54 | Full order status machine | Active |
| 55 | Simplified statuses only | **Removed** |
| 56 | Cancel before preparing | Active |
| 57 | Merchant reject | Active |
| 58 | Receipt after complete | Active |
| 59 | Optional feedback (no stars) | Active |
| 60 | Voice feedback transcription | **Removed** |
| 61 | “Where is my order?” auto-reply | Active |
| 62 | Merchant = notifications | Active |
| 63 | Shop operations phone | **Removed** — merchant controls everything on their WhatsApp |
| 64 | Per-role language | Active |
| 65 | Merchant never sees customer language label | Active |
| 66 | Human handoff | Active |
| 67 | Partial automation rules | **Removed** — busy (#5) or manual (#6) only |
| 68 | End-of-day summary | Active |
| 69 | Analytics dashboard | Active |
| 70 | Promotions / broadcast | V2 |
| 71 | Multi-branch architecture | **Removed** — one shop per merchant account |
| 72 | Webhook security + idempotency | Active |
| 73 | Message retry / delivery reliability | Active |
| 74 | Outage / offline recovery | Active |
| 75 | Production app (Laravel + Next) | Active |
| 76 | Real Wish API | Active |
| 77 | Real GPS / geofencing | Active |
| 78 | Backoffice i18n (any language) | Active |

**Active count:** 67 features (11 removed from original 78).

---

## Removed from product

**15, 19, 26, 33, 45, 52, 55, 60, 63, 67, 71**

| # | Why removed |
|---|-------------|
| 15 | No time-based menu schedules |
| 19 | No “reorder last order” shortcut |
| 26 | Zone fee + minimum only (#25); no surge/free-over-X rules |
| 33 | No OMT/cards |
| 45 | Drivers are **in-house staff** for the same shop — assign **next available** on duty, not gig “nearest driver” |
| 52 | No delivery proof photo/OTP |
| 55 | Never used (full lifecycle #54 only) |
| 60 | Text feedback only (#59) |
| 71 | Single-shop focus; no multi-branch |
| 63 | No separate kitchen/counter phone — alerts go to **merchant** WhatsApp only |
| 67 | No per-order exception rules — **busy (#5)** or **manual (#6)** only |

---

## MUST HAVE — production-grade core

### Core identity & AI
**1, 2, 3, 4, 5, 6, 7**

### Menu / cart / ordering
**8, 9, 11, 12, 13, 16**

### Customer
**17, 18** — Profiles, saved addresses (no reorder #19).

### Delivery & addressing
**22, 23, 24, 25, 27** — Pin + building + floor + zones (no #26 advanced fees).

### Payments
**28, 29, 30, 31, 32** — Cash + Wish only.

### Scheduling / operations
**35, 36, 37, 38, 39**

### Drivers / dispatch
**40, 41, 42, 43, 44, 46, 47, 48, 49, 50, 51, 53** — In-house staff; **auto-assign next available** (#41), not #45.

### Order lifecycle
**54, 56, 57, 58, 59, 61** — Full status machine; text feedback only.

### Merchant experience
**62, 64, 65, 66, 68, 69**

### Platform / infrastructure
**72, 73, 74, 75, 76, 77, 78** — Security, retries, outage recovery, production app, Wish API, GPS, **backoffice in any language**.

---

## SHOULD ADD — V2

**10, 14, 20, 21, 34, 70**

---

## Driver assignment rule (replaces #45)

When **auto-assign** runs (#41):

1. Filter drivers: `on_duty`, `available`, no `active_order_id`.
2. Pick the **next available staff driver** (queue / round-robin / manual priority in backoffice).
3. **Do not** use gig-style “nearest external driver” logic.

---

## Subscription killers (sell to merchants)

| # | Feature |
|---|---------|
| 5 | Busy mode |
| 29 | Wish auto-confirm |
| 41 | Auto-assign staff driver |
| 61 | Auto “where is my order?” |
| 68 | EOD summaries |
| 69 | Analytics |

---

## Customer “wow”

| # | Feature |
|---|---------|
| 3 | AI natural ordering |
| 18 | Saved addresses |
| 48 | Live tracking |
| 50 | Dynamic ETA |
| 58 | Receipt |
| 61 | Instant order status |

---

## Build phases

### Phase 1 — Foundation
75, 72, 73, 74, 78, 1–7, 35, 62–66, 64–65

### Phase 2 — Ordering core
8–9, 11–13, 16, 3–4, 17–18, 22–27, 28–32, 56–57

### Phase 3 — Operations & dispatch
36–39, 40–44, 46–51, 48–50, 54, 47, 53, 41–43

### Phase 4 — Merchant value
58–59, 61, 68–69, 38

### Phase 5 — Integrations
76, 77

### Phase 6 — V2
10, 14, 20, 21, 34, 70

---

## Demo vs production

| Asset | Scope |
|-------|--------|
| `demo/index.html` | WhatsApp flow (busy mode, staff drivers) |
| `backoffice/index.html` | Setup UI + any-language i18n |
| Production | All **MUST HAVE** active numbers above |

When spec and this file differ, **this file wins** for scope; **PRODUCT-SPEC.md** wins for behavioral detail.
