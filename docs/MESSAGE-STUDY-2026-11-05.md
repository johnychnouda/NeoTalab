# Fewer messages, nothing broken: the study (5 November 2026)

Goal: a shop should pay Meta as little as possible, NeoTalab should stay professional, and nothing may go missing or break. Everything here is measured in the lab or read from the code. Where I estimate, it says so.

## 1. The bottom line, with a correction

**I told you earlier a 300-order shop could go from $43 to about $25, and with the form to about $9, and that a message count of 6 per order was reachable. The careful count below says that was too optimistic.** The reason: for shops that deliver, more than half of the messages come after the order is placed (the driver, the status updates, the team), and the form checkout does not touch those.

Corrected, for a typical shop (mix below) at Meta's higher Lebanon rate:

| What we do | Messages per order, all included | 150 orders a month | 300 orders | 600 orders | Orders before paying |
|---|---|---|---|---|---|
| Today | about 16 | $14 | $39 | $88 | 62 |
| Simple cuts (section 5, groups A to C) | about 12 | $9 | $29 | $67 | 78 |
| Plus lean team and driver messages (group D) | about 11 | $7 | $25 | $61 | 86 |
| Plus the form checkout (group E) | about 9 | $5 | $20 | $49 | 102 |

A pickup-only shop is much cheaper (about 10 messages today, 7 after everything: about $21 and $11 at 300 orders). A delivery-heavy shop with drivers is the expensive case (about 20 today, 11 after everything: about $52 and $24 at 300 orders).

**So the honest answer:** with everything done, a typical shop at 300 orders pays Meta about **$20 to $25 a month instead of about $39**. That is a real saving (about 35 to 45%), but not the 75% I implied. Below about 100 orders a month it is already free or nearly free.

## 2. Every message NeoTalab can send

Counted from the code (jobs and services). "Window" means it is sent inside the 24 hours after the person last wrote to the shop's number; outside it needs an approved template. Since 1 October 2026 every one of these counts toward the shop's 1,000 free messages and costs about $0.0105 after.

**To the customer** (all inside the window unless noted)
| Message | When | Needed? |
|---|---|---|
| Welcome and menu list | first message, or "menu" | yes |
| "How many?" | after picking an item | optional |
| "Added" with the cart and buttons | after the quantity | yes |
| Area list | several zones and no pin | only then |
| Location request | delivery | yes |
| "Add building and floor" | after the pin | optional |
| Cash or Whish | only if the shop takes Whish | only then |
| Dollars, lira or split | only if the shop has a lira rate | yes, the customer chooses |
| Receipt with confirm, edit, cancel | before the order | yes |
| Order confirmed (or "sent to the shop") | after confirm | yes |
| Accepted by the shop (with Whish instructions) | shops that accept by hand | yes |
| "Could not take it" | shop did not accept in time | yes |
| Whish: thanks, payment received, refund, timeout | Whish orders | yes, it is money |
| On the way, ready for pickup, delivered with the rating question | status changes | delivered yes, others nice |
| "Taking a little longer" | late orders, up to a limit | optional |
| Rating thanks or apology | after a rating | apology yes, thanks optional |
| Cancel yes/no and result, handoff to a person, photo received, closed, out of area, minimum order, sold out, could not process, "I was interrupted, send again" | when they happen | yes |
| Bookings: free times, slot taken, booked, deposit instructions, reminder (template outside the window), expiry, changes | appointment shops | yes |

**To the team** (staff numbers and drivers; staff outside the window get an approved template)
| Message | When |
|---|---|
| New order with Accept or Can't take (each staff number) | shops that accept by hand |
| "Confirmed, prepare it now" with the status buttons | after accepting |
| Whish payment to check (and a reminder after 30 minutes) | Whish orders |
| Cancellation, refund, handoff, item to approve, low rating, booking alerts | when they happen |
| Driver: shift start reply, delivery with buttons, map pin, "on the way" with new buttons, "completed", cancellation, shift summary | every delivery |

**From NeoTalab's own number** (you pay these, not the shop): welcome, billing reminders, nightly report, system alerts. A few a month per shop.

## 3. What one order really sends (measured)

I ran each case in a test with the real code and counted every message by recipient.

| Case | To the customer | To the team and driver | Total |
|---|---|---|---|
| Pickup order, solo shop, menu buttons | 6 | 0 | **6** |
| Delivery, solo shop, one driver | 12 (9 until confirmed, then on the way, delivered, rating thanks) | 4 (delivery, map pin, "on the way", "completed") | **16** |
| Delivery, a manager must accept, one driver | 13 | 6 | **19** |
| Whish payment, manager checks, driver | about 15 | about 7 | **about 22** |
| Typed order through the assistant | 2 to 3 | 0 | **2 to 3** |
| A chat that does not end in an order | 1 per question | 0 | 1 each |

Real assistant orders may add a button checkout after the typed part, so "2 to 3" is the best case.

The mix I used for the "typical shop": 35% pickup, 40% delivery with a driver, 10% manager accepts, 5% Whish, 10% typed, plus about 3.75 messages per order for chats that do not end in an order (1.5 such chats per order, 2.5 messages each). **That mix and the 3.75 are my assumptions; the first real shops will replace them.**

## 3b. A busy restaurant (100 orders a day)

A restaurant often orders **three items or more** at a time, and the menu costs **3 messages per extra item** (the item list, "how many?", "added"; I counted it in a 3-item test: 9 messages before checkout even starts). So the "typical shop" numbers above understate a restaurant.

Assumptions: 3,000 orders a month, 3 items per order, 70% delivery with a driver and 30% pickup, plus 3.75 messages per order for chats that do not end in an order. Meta's higher Lebanon rate; at the lower rate every bill is about 13% smaller.

| Plan | Messages per order | Meta bill, 3,000 orders | Per order | Per day |
|---|---|---|---|---|
| Today (menu buttons) | about 23 | **about $706** | 24 cents | $24 |
| Lean menu (groups A to D) | about 16 | about $506 | 17 cents | $17 |
| Customers type the whole order in one message | about 11 | about $339 | 11 cents | $11 |
| Form or catalog cart | about 9 | **about $286** | 9.5 cents | $9.5 |
| Form, and 20% of customers come from Click-to-WhatsApp ads | | about $226 | | |
| Form, and 40% come from ads | | about $167 | | |

The same restaurant at 50 orders a day: $348 today, $248 lean menu, $164 typed, $138 form.

**What this means:** the menu alone only gets a busy restaurant from about $706 to about $506. The big drop comes from letting the customer send the **whole order at once**, typed or by form. That is why, for restaurants, the form or catalog cart is not optional polish: it is the main saving. At about 9 cents an order, Meta is about 0.4% to 1% of sales if the average order is $10 to $25.

**What cannot lower it further, checked:** Meta's free 7-day window exists only for customers who arrive from a Click-to-WhatsApp ad (not for Page buttons, QR codes or links). The Groups API (one message to a kitchen group) needs an Official Business Account, holds 8 people at most, cannot send buttons, and is priced per message anyway. Meta's volume discounts apply only to utility messages at volumes far above one restaurant, and count across a whole business portfolio (which would mean NeoTalab becoming Meta's reseller and paying the bills itself). So the floor for a restaurant at this size is about **$170 to $290 a month**.

## 4. What limits the design (Meta's rules)

- A button message holds **3 buttons**; a list message holds **10 rows**. So "confirm in dollars, in lira, split, edit, cancel" must be a list, not buttons.
- A location request message carries only its own "send location" button.
- Buttons in an older message stay tappable, so a driver can tap "Delivered" on the first delivery message.
- Messages inside the window: free for the first 1,000 per number each month, then about $0.0105. Utility templates inside the window are charged since 1 October. Marketing templates cost about four times as much. Customers writing to the shop is always free.
- Messages that are not delivered are not charged.
- Customers who arrive from a Click-to-WhatsApp ad get 7 days of free messages.
- **A shop with no card in WhatsApp Manager cannot send paid messages.** After the free 1,000 its customers would stop getting replies. Today NeoTalab records failed deliveries as an alert for you, but does not warn the shop beforehand.

## 5. The lean design: what changes, what never changes

**Never changes (this is the professional core):** welcome and menu; item added with price; the receipt with the total in dollars and lira and the customer's choice of dollars, lira or split; confirm, edit and cancel; order confirmed with the time; delivered with the thank-you and rating question; the human handoff; every error and refusal message; every Whish money message; bookings and deposits; the closed message.

| Group | Change | Saves per order | Risk | Safeguard |
|---|---|---|---|---|
| A | **Quantity defaults to 1** with a "change quantity" button on the "added" message | 1 per item (60% of items) | low | The change button opens the old "how many" step; tests for 1, 2, "more" |
| A | **Skip "which area?"** when the pin already falls in a zone or the shop has one zone | 1 | low | Old step stays when the pin is outside every zone or zones are not drawn |
| A | **Location request and building together**: the request says "send your location, then type building and floor". If only the pin arrives, the old "add details" question still comes | 0.7 | low | Fallback keeps the old question |
| B | **Receipt and payment choice in one list message**: the receipt text, then rows "Confirm, pay in dollars", "Confirm, pay in lira", "Confirm, split cash", cash or Whish rows when the shop takes Whish, "Edit", "Cancel". Tapping a row confirms | 1 to 2 (3 for Whish shops) | medium | Split still asks its amount; edit and cancel behave exactly as now; same totals and rounding; every existing checkout test must pass |
| C | **One fixed set of customer updates**: confirmed (with the time), on the way, delivered with the rating question. "Ready for pickup" only where a team member presses it. "Taking longer" only when an order is really late | 0 to 1 | low | No setting to switch; money and refusal messages are never affected |
| C | **Thanks for a rating** only when the rating is low (the apology) | 0.8 | low | Low ratings still alert the team |
| D | **Team alert to the manager numbers only**, not every staff number | 0 to 2 | low | Uses the manager and staff roles already in the team list; the manager sees every order |
| D | **No "confirmed, prepare it now" message** for shops that use drivers (the driver message already carries the order) | 1 | low | Shops without drivers keep it |
| D | **Lean driver mode**: assignment and map pin, then the first message's buttons stay in use; a short "completed" only at the end | 1 to 2 | medium | The driver still gets an answer after every tap; tested with a real flow |
| E | **A form or a catalog cart** for items, area, address and payment: the customer picks several items in one screen and sends one order. The chat stays as the fallback when the app does not show it | 3 to 5, and much more for orders with several items | high effort | Built after the first real shops; see section 6b for why busy restaurants need it |
| E | **Typed or voice order in one message** ("2 shawarma, 1 fries, 1 cola") and a hint in the welcome message that this works | 3 per extra item | tiny | The assistant already understands several items in one message; checked with the real assistant in the lab |
| F | Click-to-WhatsApp ads | all messages for ad customers | none | Advice to shops, nothing to build |
| F | Fill in the shop's WhatsApp Business profile (hours, address, description) so fewer customers ask | fewer non-order chats | none | Add to the sign-up checklist |

## 6. Making sure nothing breaks (no switch: one version for every shop)

Decision (5 November): there is no "classic" and "lean" choice. Every shop gets the lean version, so no shop ever pays the higher bill by accident or by habit.

1. **Shipped in steps, each step complete on its own.** Every merged or shortened step keeps its old question as a fallback inside the same flow (for example, if only the pin arrives, the "add details" question still comes).
2. **Tests first:** the full existing suite (665) must pass, then new tests for each changed step: quantity 1, 2 and more; one zone, several zones, pin outside every zone; location with and without building; receipt-and-payment rows for dollars, lira, split, Whish, edit and cancel; minimum order; closed shop; sold out; saved address; driver flow end to end; rating thanks low and high; multi-item orders of 1, 3 and 8 items.
3. **Lab proof before it goes live:** the scale lab with the real assistant (900 customers) must show no wrong cart, no missing reply, the same totals as today; the recovery tests (killed workers, Meta down) rerun.
4. **Rollback is a redeploy**, not a setting a shop can touch. Production health is checked during and after each deploy, as I did on 4 November.
5. **A message counter per order** (how many messages each order really used, by kind) goes in with the first step. It replaces my assumptions with real numbers and shows the real saving.

## 7. Billing awareness (so no shop is ever surprised or cut off)

- **A usage card for each shop** (read only, in the dashboard): messages this month, free ones left, estimated Meta bill. No daily controls, as you decided.
- **A WhatsApp message to the shop owner at 800 of 1,000 free messages**: "add a card in WhatsApp Manager now so replies keep going" with the link NeoTalab already has.
- **A clear alert when Meta refuses a message for a payment problem** (I believe Meta reports code 131042; to confirm on the first case), so you can call the shop before customers notice.
- **A step in sign-up**: add the card before the first 1,000 messages.
- Update the owner portal's usage estimate to the 1 October rules (it already assumes 1,000 free; check the rate against the real invoice of the first shop).

## 8. Order of work and effort (estimates)

1. **Counter and usage card, with the 800-message warning:** about 2 days. Protects shops from being cut off and gives real numbers.
2. **Groups A to D** (quantity default, area skip, location with building, receipt and payment in one message, fixed updates, team alert to managers, lean driver messages), with tests and the lab proof: about 6 to 8 days.
3. **Typed or voice order in one message** (a hint in the welcome message, and checking the assistant with 3 to 8 items in the real-assistant lab): about 2 days. **Biggest saving per day of work for restaurants.**
4. **The form or catalog cart:** about 1 to 3 weeks. Built right after step 3 because of the restaurant numbers above.

## 9. What I could not verify

- Meta's exact Lebanon rate: sources say $0.0091 or $0.0105; I used the higher one. The first shop's WhatsApp Manager will show it.
- Whether a Flow message and a list message cost exactly the same as a text (Meta's page lists no extra charge for them).
- The share of non-order chats and the real mix of order types: assumptions until real shops send.
- Real-assistant orders (the lab used the fixed test assistant for the counts above).
- Appointment shops: counted from the code, not measured.
