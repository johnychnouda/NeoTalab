# NeoTalab — Cursor Build Prompt

> Read **`PRODUCT-SPEC.md`** (behavior) and **`PRODUCT-SCOPE.md`** (locked feature #1–78, MUST HAVE vs V2).  
> **Core problem:** merchant too busy — NeoTalab runs WhatsApp ordering + delivery automation.

## Positioning

**AI WhatsApp Commerce OS** — not a marketplace. Replace chaotic WhatsApp ops for Lebanese merchants.

## Stack

- Node.js (Express) API + PostgreSQL + JWT auth — lives in `api/`  
- Next.js 15 (App Router, React, JavaScript) frontend — lives in `web/` (owner portal + merchant backoffice, EN/AR/FR with RTL)  
- OpenAI GPT-4o + WhatsApp Business Cloud API  
- GPS / geofencing provider for #48, #50, #77  
- Wish integration #76  

## MUST HAVE highlights (see PRODUCT-SCOPE.md)

- **Menu/cart:** #11, #12, #13 essential with #8, #9, #16  
- **Retention:** #17, #18  
- **Lebanon delivery:** #22–27 (zone fee + min only; no #26)  
- **Payments:** #28–32  
- **Ops:** #35–39 (#37 prep engine, #39 rush queue)  
- **Premium dispatch:** #40–51, #53 — include **#48 live tracking + geofencing**  
- **Lifecycle:** #54 full statuses (ignore #55)  
- **Merchant:** #62–69 (no #67 — busy #5 or manual #6 only)  
- **Platform:** #72–78 including #74 outage recovery (single shop; no #71)  

## V2 later

#10, #14, #20, #21, #34, #70 — removed: #15, #19, #26, #33, #45, #52, #55, #60, #63, #67, #71.

## Key services (production)

- `AIService` — draft, modifiers, scheduled time, notes  
- `MenuService` / `CartService` — #11–13  
- `CustomerProfileService` — #17–19  
- `DeliveryZoneService` — #25, #27  
- `PrepTimeService` / `RushQueueService` — #37, #39  
- `DispatchService` — #41–44, #46 — next available staff driver  
- `TrackingService` — #48–50, #49 fallback  
- `WishPaymentService` — #29–31  
- `OrderService` — #54 state machine  
- `OrderStatusInquiryService` — #61  
- `HandoffService` — #66  
- `AnalyticsService` / `EndOfDayReportService` — #68–69  
- `WhatsAppService` — #72–73  
- `OutageRecoveryService` — #74  

## Build phases

Follow **PRODUCT-SCOPE.md** → Build phases 1–6.

## Frontend (production)

- `web/` — Next.js app: `/owner` (owner portal) + `/backoffice` (merchant backoffice), demo-mode fallback when API is offline

## Previews (legacy / demo only)

- `demo/index.html` — WhatsApp flow subset  
- `owner/index.html` + `backoffice/index.html` — legacy vanilla HTML/CSS/JS dashboards, superseded by `web/`  
