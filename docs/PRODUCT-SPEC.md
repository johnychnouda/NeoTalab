# NeoTalab — Product Spec (WhatsApp Operations)

> **WhatsApp = operations** · **Dashboard = backoffice only** (profile, menu, Wish account, drivers registry)  
> **Locked scope (feature #1–78):** see **`PRODUCT-SCOPE.md`**

---

## Why NeoTalab exists (core problem)

**The merchant is busy** — cooking, serving walk-ins, counting cash, managing the shop — and **cannot keep using the phone** for every WhatsApp message.

Without NeoTalab, orders pile up in chat, customers wait, drivers are not coordinated, and the business loses sales.

**NeoTalab runs the WhatsApp business when the merchant cannot:**

| Who is busy | What NeoTalab does instead |
|-------------|----------------------------|
| **Merchant (owner)** | AI answers customers, builds orders, sends status updates; **auto-rules** confirm and advance orders when enabled |
| **Kitchen / counter staff** | Can use simple WhatsApp buttons on a **shop phone** (optional): preparing, ready for pickup |
| **Staff drivers** | Auto-assigned when available; delivery steps (left store, nearby, arrived) **without merchant taps** |
| **Merchant (later)** | Gets **alerts only when needed** (Wish payment to verify, problem, VIP) + end-of-day summary |

The merchant sets up the shop once in **backoffice**, then the system **keeps working on WhatsApp** during the rush.

---

## Busy-mode vs manual-mode

| Setting | Behavior |
|---------|----------|
| **Auto-accept (busy mode)** ON | Cash orders: AI draft → customer confirms → **order auto-confirmed** → preparing → assign next available driver (delivery) or ready-for-pickup flow — **no merchant tap required** |
| **Auto-accept** OFF | Merchant must tap **Confirm** / **Reject** on cash; picks driver on delivery — for owners who want control |
| **Demo** (`demo/index.html`) | Implements **busy mode only** (auto-confirm cash, auto-assign driver). Manual mode is spec-only until built. |
| **Wish payment** | When money **hits the merchant Wish account**, NeoTalab **auto-confirms** the order (webhook/API match on amount + account) — merchant does not tap |
| **Exceptions** | Unknown product, blocked customer, low AI confidence → notify merchant (or manager phone) |

Default for small busy shops in Lebanon: **Auto-accept ON for cash** + **staff drivers auto-assigned**.

---

## Product principles

1. **Shop runs without the owner on the phone** — automation + staff drivers + AI; merchant intervenes only when necessary.
2. Customers use **WhatsApp** with **interactive buttons**; AI handles messages when the merchant is busy.
3. **AI proposes** the order; customer confirms pickup/delivery and payment; system **auto-confirms** cash when busy mode is on.
4. **Payments v1:** `cash` or `wish` only. **Wish:** customer pays merchant Wish account → system **auto-confirms** when payment is detected (merchant notification only, no tap). **Cash:** pay at pickup or to driver on delivery.
5. **Fulfillment:** Customer chooses **Pickup** or **Delivery** after confirming the order draft. **Delivery:** WhatsApp **location pin** + **building name** + **floor** — all **before** payment.
6. **Drivers are shop employees** — in-house staff for the same store (not external/gig couriers). **Busy mode:** next available driver is **auto-assigned**. **Manual mode:** merchant picks from the staff list. Orders are prepared at the shop; the driver **leaves the store** to deliver (not external pickup).

---

## Drivers (in-house staff)

| Concept | Rule |
|---------|------|
| Who | Employees of the merchant (e.g. Ahmad works for Joe’s Snacks) |
| Registry | Backoffice: name, phone, active — **private pool per shop** |
| Assignment | **Busy mode:** next **available** staff driver **auto-assigned** (no Accept/Reject). **Manual mode:** merchant picks from available staff list |
| Pickup orders | **No driver** — customer collects at the shop |
| Delivery orders | Driver is already at the store → takes the order and goes to the customer |
| Driver taps | **Two only:** Left the store · Order delivered. Nearby/Arrived = automatic (live tracking) |

---

## Order state machine

```text
DRAFT
  → customer: Confirm / Edit / Cancel
FULFILLMENT_CHOICE
  → customer: Pickup / Delivery
DELIVERY_ADDRESS (delivery only) — **before payment**
  → Step 1: customer sends **WhatsApp location pin** (lat/lng for navigation + geofencing)
  → Step 2: customer sends **building name**, **floor** (required), **additional info** (optional)
PAYMENT_CHOICE
  → customer: Cash / Wish
AWAITING_PAYMENT (Wish only)
  → customer sends amount to merchant `wish_account_phone`
  → NeoTalab detects incoming Wish credit (integration/webhook) → **AUTO-CONFIRMED**
CASH_CONFIRM
  → busy mode (`auto_accept_orders` = true): **AUTO-CONFIRMED** immediately after customer selects cash
  → manual mode: merchant [Confirm] / [Reject] → then CONFIRMED
CONFIRMED
  → customer notified: "Order confirmed"
PREPARING
  → busy mode: **automatic** on confirm (no merchant tap)
  → manual mode: merchant [Start preparing] (optional; can default to auto)
PICKUP PATH:
  → READY_FOR_PICKUP (merchant/staff: [Ready for pickup]) → customer notified → COMPLETED → optional feedback
DELIVERY PATH:
  → busy mode: **auto-assign** next available staff driver
  → manual mode: merchant [Assign driver] from available staff
  → driver: **[Left the store — on my way]** (only tap while leaving — starts GPS tracking)
  → system (live tracking): ETA to customer + auto **Nearby** + auto **Arrived** — **driver does not tap while driving**
  → driver: **[Order delivered]** when handing order to customer → merchant notified
  → COMPLETED → optional feedback
```

---

## Customer WhatsApp flow

| Step | Message / buttons |
|------|-------------------|
| 1 | Sends order (Arabic/English/French/voice) |
| 2 | AI **order draft** → `Confirm` / `Edit` / `Cancel` |
| 3 | `Pickup` / `Delivery` |
| 4a | **Delivery · step 1:** Send **WhatsApp location** (for driver navigation) |
| 4b | **Delivery · step 2:** Type **building name** + **floor** (required) + **additional info** (optional: gate, directions) |
| 5 | `Cash` / `Wish` |
| 6a Cash · **busy mode** (default) | "Cash on pickup/delivery" → **order confirmed immediately** → **Preparing** |
| 6a Cash · **manual mode** | "Waiting for the shop to confirm…" → after merchant Confirm → **Order confirmed** → **Preparing** |
| 6b Wish · **both modes** | "Send {total} to Wish {phone}" → when payment **lands in merchant account**, order **auto-confirmed** → **Preparing** |
| 7 · Pickup | **Preparing** → **Ready for pickup** at shop → optional **feedback** |
| 7 · Delivery | **Preparing** → driver assigned → heading → ETA → Nearby → Arrived → **Delivered** → optional **feedback** |

### “Where is my order?” (anytime while order is active)

Customer can ask in **free text** at any point after the order is confirmed (until completed or cancelled). NeoTalab replies **automatically** in the **customer’s language** — merchant is not involved.

| Customer might say | Examples |
|--------------------|----------|
| English | “Where is my order?”, “Status?”, “How long?” |
| Arabic | “وين الطلب؟”, “قديش بدو يوصل؟” |
| French | “Où est ma commande?” |

**Detection:** keyword + AI intent on inbound message when `order.status` is not `delivered` / `cancelled`.

**Reply rules (delivery):**

| Order status | Customer reply includes |
|--------------|-------------------------|
| Confirmed / preparing | Order #, **Preparing**, driver name if already assigned |
| Driver assigned (not left store) | **Preparing** · {Driver} will deliver |
| Out for delivery | **{Driver} is on the way** · **estimated arrival** (if available) |
| Driver nearby (geofence) | **{Driver} is nearby** |
| Driver arrived (geofence) | **{Driver} has arrived** at your building |
| Delivered | “Your order was delivered” + feedback prompt if not done yet |

**Reply rules (pickup):**

| Order status | Customer reply includes |
|--------------|-------------------------|
| Preparing | Order #, **Preparing** |
| Ready for pickup | **Ready for pickup** · shop address |

**Rules**

- Include **order reference** (`#ORD-…`) in every status reply.
- Include **ETA** only when driver has tapped **Left the store** and ETA is available.
- Do **not** expose driver live coordinates or a map link in v1 (status text only).
- Merchant does **not** see these Q&A messages unless escalated (optional v2).

---

## Merchant WhatsApp flow

Merchant messages are always in the **merchant’s language**. Merchant never sees customer or driver language settings.

| Event | Busy mode (default) | Manual mode (`auto_accept_orders` = false) |
|-------|---------------------|---------------------------------------------|
| New order | Read-only summary; cash/Wish **auto-confirm** when rules met | Summary + **`[Confirm]`** / **`[Reject]`** on cash (Wish still auto-confirms on payment) |
| Preparing | **Automatic** after confirm — notification only | Optional **`[Start preparing]`** (or auto) |
| Pickup · ready | Staff/merchant: **`[Ready for pickup]`** | Same |
| Delivery · driver | **Auto-assign** next available driver — notification only | **`[Assign driver]`** from available staff list |
| Driver updates | Read-only: Heading · ETA · Nearby (auto) · Arrived (auto) · Delivered | Same |
| Wish | Preparing when payment **detected** — no merchant tap | Same |
| Feedback | Read-only card with customer message (in merchant language if translated) | Same |

---

## Driver WhatsApp flow (shop employee) — minimal taps (safe driving)

Driver uses the phone **only twice** per delivery. **Nearby** and **Arrived** are **automatic** via live location tracking (no tapping while driving).

| Event | Driver action | System (live tracking) |
|-------|---------------|----------------------|
| Assigned | Notification: **building + floor** + location pin — **no Accept/Reject** if driver is available | — |
| Leaves store | **`[Left the store — on my way]`** | Start live tracking · **estimated arrival** to customer |
| En route | *(no taps)* | When within **~500 m** → notify customer & merchant: **Nearby** |
| At customer | *(no taps)* | When within **~50 m** → notify: **Arrived** |
| Handoff | **`[Order delivered]`** | Notify **merchant** + customer · optional **feedback** (not a rating survey) |

### Delivery address (customer — before payment)

| Step | Channel | Stored on order |
|------|---------|-----------------|
| 1 | WhatsApp **location message** (live pin) | `delivery_latitude`, `delivery_longitude`, `delivery_location_url` |
| 2 | Customer **text** (or voice → transcribe) | `delivery_building_name`, `delivery_floor`, `delivery_additional_info` (nullable) |

Driver and merchant cards show **building + floor + additional info** prominently; location pin is for navigation and automatic nearby/arrived updates.

### Location data (tracking)

| Source | Field |
|--------|--------|
| Customer pin | lat/lng + optional navigation link (see above) |
| Customer text | building name, floor |
| Driver | Live location after “left store”, or periodic GPS ping |
| Backend | ETA calculation · **geofencing** (nearby / arrived thresholds per tenant settings) |

### Tenant settings (backoffice)

```json
{ "geofence_nearby_meters": 500, "geofence_arrived_meters": 50 }
```

**Available = must deliver.** Driver never receives pickup orders.

---

## Customer feedback (optional — not ratings)

Friendly WhatsApp message after order is complete. **Optional** — customer can skip. Feels like feedback to the shop, not a formal survey.

### Message tone (example)

> Thank you for ordering from Joe's Snacks.  
> We hope everything met your expectations. Your feedback is welcome and optional.

| Button | Action |
|--------|--------|
| **👍 Everything was perfect** | Customer confirms satisfaction · merchant notified · thank-you reply |
| **Leave feedback** | Customer **types** or sends a **voice note** directly (no topic, no templates) |
| **Skip** | No feedback stored |

### If "Leave feedback"

1. Customer sends **their own message only** — **text** or **voice note** on WhatsApp  
   **No topic picker, no templates, no pre-written buttons.**
2. NeoTalab saves the exact message (voice → transcribe, then store text)

### Merchant sees (read-only)

```
💬 Feedback on ORD-2026-00042
Ali: "The shawarma was great but delivery took a while"
```

### Database

```php
Schema::create('order_feedback', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();
    $table->enum('sentiment', ['positive', 'neutral', 'issue'])->default('neutral');
    $table->string('topic')->nullable(); // optional, not asked from customer in v1
    $table->text('message')->nullable();
    $table->timestamps();
});
```

**No star scores.** Merchant can read feedback in backoffice history later.

---

## Database fields (orders)

```php
$table->enum('fulfillment_type', ['pickup', 'delivery']);
$table->enum('payment_method', ['cash', 'wish']);
$table->enum('payment_status', ['unpaid', 'pending_wish', 'paid'])->default('unpaid');
$table->boolean('wish_payment_detected')->default(false);
$table->timestamp('wish_paid_at')->nullable();
$table->string('wish_transaction_ref')->nullable(); // from Wish webhook when available
$table->string('delivery_building_name')->nullable();
$table->string('delivery_floor')->nullable();
$table->text('delivery_additional_info')->nullable(); // gate code, directions, landmark
$table->timestamp('scheduled_for')->nullable(); // scheduled orders
$table->unsignedSmallInteger('delay_minutes')->nullable(); // kitchen delay communicated
$table->decimal('delivery_latitude', 10, 7)->nullable();
$table->decimal('delivery_longitude', 10, 7)->nullable();
$table->string('delivery_location_url')->nullable();
$table->string('assigned_driver_name')->nullable();
$table->enum('status', [
    'pending', 'awaiting_wish', 'confirmed', 'preparing',
    'ready_for_pickup', 'awaiting_driver', 'driver_assigned',
    'out_for_delivery', 'driver_nearby', 'driver_arrived',
    'delivered', 'cancelled'
]);
```

---

## Tenants (backoffice)

**Preview UI:** `backoffice/index.html` (setup only — no live order board).

- **Languages:** English, Arabic (RTL), French — switcher in sidebar; choice saved in browser (production: `merchant_locale` on tenant)
- Shop profile, **opening hours**, **delivery zones**, menu, drivers, payments, operations, reports
- `wish_account_phone`, `accepts_cash`, `accepts_wish`
- **`auto_accept_orders`** (busy mode) — default `true` for cash orders
- **`shop_operations_phone`** — optional second WhatsApp for staff (e.g. ready for pickup) when owner is busy
- Products, categories, **staff drivers** registry (name, phone, on duty, active delivery) — **no live order handling on web**

---

## MVP operations (professional)

### Shop hours / closed

| Rule | Behavior |
|------|----------|
| Weekly hours | Per day open/close in backoffice (tenant timezone) |
| Closed | New orders blocked; auto-reply: closed + next open time |
| Overrides | Optional holiday / special closure dates |

### Delivery zone + fee + minimum

| Setting | Behavior |
|---------|----------|
| **Zones** | Named areas or radius from shop pin (backoffice) |
| **Delivery fee** | Fixed or per-zone; added to order draft total |
| **Minimum order** | Delivery only — below minimum → offer pickup or add items |
| **Outside zone** | Polite reject + suggest pickup; merchant optional alert |

Validate customer location pin against zone **before** payment.

### Sold out / unavailable items

| Rule | Behavior |
|------|----------|
| Product flag | `is_available` / sold out today in backoffice |
| AI draft | Never confirm unavailable SKU; suggest alternative |
| Mid-rush toggle | Merchant marks sold out in backoffice → immediate effect on new drafts |

### Cancel & reject rules

| Who | When | Action |
|-----|------|--------|
| Customer | Before **preparing** | Cancel button / message → `cancelled` |
| Customer | After preparing | “Contact the shop” / merchant alert (no auto-cancel) |
| Merchant | Before preparing | **Reject** (manual mode or exception) |
| Merchant | Wish paid + cancel | Refund process documented (manual v1) |
| System | Wish timeout (no payment) | Cancel awaiting order after TTL; notify customer |

### Failure flows

| Situation | Customer | Merchant |
|-----------|----------|----------|
| **No driver available** | “No driver right now — preparing, we’ll update you” + retry assign | Alert to assign or wait |
| **Kitchen delay** | “Running **+15 min** late” (configurable) | Optional one-tap delay |
| **Wish wrong amount** | “Amount doesn’t match — send {expected} or contact shop” | Alert with expected vs received |
| **Wish timeout** | “Payment not received — order cancelled” / resend instructions | Notification |
| **No location pin** | Reminder to send location before payment | — |
| **Low AI confidence** | Handoff message | Human handoff alert |

### One active delivery per driver

| Rule | Behavior |
|------|----------|
| Assignment | Driver with `active_order_id` set → **not** auto-assigned again |
| On delivered | Clear `active_order_id`; driver becomes available |
| Backoffice | Show “On delivery · ORD-…” on driver row |

### Receipt after complete

WhatsApp message to customer when order completes (before feedback):

```
Receipt — Joe's Snacks
#ORD-2026-00042
2× Shawarma, 1× Pepsi
425,000 ل.ل · Cash
Pickup / Delivery · Thank you
```

### Wish wrong amount / timeout

| Case | System |
|------|--------|
| Under/over pay | Match amount ± tolerance; else hold + customer message + merchant alert |
| Timeout | `awaiting_wish` → after N minutes cancel + notify both parties |
| Reference | Encourage order # in Wish note when possible |

### Cash reconciliation (backoffice)

End-of-day or on-demand report (read-only):

- Delivered + cash orders: expected cash total  
- Wish total vs detected payments  
- Export CSV (v1.1)

### Webhook security + duplicate orders

| Control | Implementation |
|---------|----------------|
| Signature | Verify Meta `X-Hub-Signature-256` on every webhook |
| Idempotency | `whatsapp_message_id` unique — same inbound message never creates two orders |
| Replay | Reject old timestamps beyond window |

### Human handoff

| Trigger | Behavior |
|---------|--------|
| Low AI confidence, angry customer, unknown intent | Pause bot for chat · notify merchant · “A team member will reply shortly” |
| Merchant reply on shop phone | Bot paused until merchant ends handoff (backoffice or keyword) |

### End-of-day summary (merchant WhatsApp or backoffice)

- Orders count · revenue cash vs Wish · cancelled · avg prep time  
- Feedback highlights · drivers deliveries completed  

### Scheduled orders (“for 7:00 PM”)

| Rule | Behavior |
|------|----------|
| Detection | AI extracts requested time from draft or follow-up |
| Validate | Within shop hours + min lead time (e.g. 30 min) |
| Flow | Confirm → `scheduled_for` stored → kitchen starts at appropriate time |
| Customer updates | “Scheduled for 7:00 PM” in status + receipt |

### Kitchen delay message (“+15 min”)

| Rule | Behavior |
|------|----------|
| Merchant | One-tap **+15 min** (or custom) on WhatsApp or backoffice |
| Customer | “Your order is running about **15 minutes** late — sorry for the wait” |
| ETA | Adjust displayed ETA if driver not yet left |

### Per-role language (recap)

- Customer / merchant / driver each get messages in **their** language (autodetect from messages; merchant never sees “customer language: EN”).
- Documented in customer flow; merchant cards use translated summaries.

---

## Payments

| Method | Flow |
|--------|------|
| **cash** | Pay on pickup or to driver on delivery; merchant may mark paid at end |
| **wish** | Customer sends to merchant Wish → system detects credit → **order auto-confirmed** |

**Not in v1:** OMT, cards, Stripe for customers.

### Wish payment detection (backend)

```text
Customer pays merchant Wish account
  → Wish sends webhook / NeoTalab polls merchant account (when API available)
  → Match: tenant_id + amount (+/- tolerance) + order_id in note if possible
  → Set payment_status = paid, wish_payment_detected = true
  → OrderService::autoConfirm($order)
  → WhatsApp to customer: "Payment received — order confirmed"
  → WhatsApp to merchant: notification only (optional read-only card)
  → Start preparing / assign driver per fulfillment_type
```

MVP without live Wish API: manual admin override or demo simulation; production requires merchant Wish integration.

---

## Implementation priority

See **`PRODUCT-SCOPE.md`** for MUST HAVE (#) and phased build. Summary:

1. **Platform:** #75, #72, #73, #74, #71, #78  
2. **Core + AI + hours:** #1–7, #35, #62–66, #64–65  
3. **Menu/cart:** #8–9, #11–13, #16  
4. **Customer + address + pay:** #17–19, #22–27, #28–32, #56–57  
5. **Ops + dispatch + tracking:** #36–39, #37–38, #40–51, #48–50, #54, #47, #44–46  
6. **Merchant value:** #58–61, #60, #67–69, #68, #26  
7. **Integrations:** #76, #77  
8. **V2:** #10, #14, #15, #34, #70 (+ #20, #21, #52 as needed)  

**Ignore #55** (simplified statuses). **Full lifecycle #54** is required.

**Previews:** `demo/index.html` (WhatsApp) · `backoffice/index.html` (setup + i18n)
