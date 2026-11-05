# What NeoTalab costs, what to charge, and what shops pay Meta (4 November 2026)

All figures are USD per month. They come from your real Railway bill, the AI usage I measured in the tests, the messages one order really sends, and Meta's price pages. Where I had to assume something, it says so. This is my own analysis, not financial or tax advice.

## The short version

1. **Your cost is small and mostly fixed.** About **$20 a month for Railway** up to roughly 300 shops, plus about **$0.30 to $0.70 per shop per month for the AI**. At 100 shops that is about **$60 to $100 a month in total**, against **$3,000 of subscriptions** at your current $30.
2. **The $30 price is not limited by your cost.** Your cost per shop is $0.50 to $3. The price is limited only by what a Lebanese shop will pay. The margin is about 95 to 98%.
3. **The shop's real extra cost is Meta, and it changed on 1 October 2026.** Replies inside the 24-hour window used to be free. Now each number gets **1,000 free messages a month**, then every message costs about **$0.0105**. One order sends about 17 messages, so a shop with more than about 57 orders a month starts paying. A shop with 300 orders pays Meta about **$43 a month**, more than it pays you.
4. **Two settings need your attention now** (see "Risks"): Railway's $20 spending limit and the OpenAI balance.

## 1. What it costs you (platform owner)

### What you pay today
| Item | Monthly | Notes |
|---|---|---|
| Railway (API, web, MySQL, queue) | **about $17 to $20** with 40 workers | Last billing cycle (6 Oct to 4 Nov) was $9.42, almost all memory. Raising the queue to 40 workers adds about 0.75 GB, about $7.50 |
| OpenAI (the assistant) | about **$0.0001 to $0.0004 per AI call** | Production uses `gpt-6-luna` ($0.10 per million input tokens, $0.01 cached, $0.50 output, from your usage config). My tests used `gpt-4o-mini` and cost $0.00041 per call; the same tokens on luna come to $0.00013, and I allow up to $0.0003 for its extra "thinking" |
| Domain neotalab.com | about $1 | Namecheap, yearly |
| Email, backups, Google Maps | about $1 to $2 | Free tiers cover this size. Resend is free to 3,000 emails a month |
| Meta, for your own platform number | about $0.03 per shop | Welcome and billing-reminder templates, about 3 a month |
| Your time, taxes, Whish fees | not included | The biggest real cost; I can't estimate it. Check what Whish charges to receive a transfer |

### How it grows
Assumption: each shop gets **300 orders a month** (10 a day). A "chat" averages **8 AI calls per order** (this counts people who ask and don't order). The 150-order case is in brackets.

| Shops | Railway | AI | Other | **Your total** | Per shop | Subscriptions at $30 | Left over |
|---|---|---|---|---|---|---|---|
| 10 | $19 | $3 to $7 | $2 | **$24 to $29** ($23 to $25) | about $2.50 | $300 | about $270 |
| 50 | $19 | $16 to $36 | $4 | **$38 to $59** ($30 to $41) | about $1 | $1,500 | about $1,440 |
| 100 | $22 | $31 to $72 | $5 | **$58 to $99** ($43 to $63) | $0.60 to $1 | $3,000 | about $2,900 |
| 300 | $35 | $94 to $216 | $12 | **$140 to $262** ($93 to $154) | $0.50 to $0.90 | $9,000 | about $8,700 |
| 1,000 | $120 | $312 to $720 | $34 | **$466 to $874** ($310 to $514) | $0.50 to $0.90 | $30,000 | about $29,100 |

Railway for 300 and 1,000 shops is a rough estimate (more workers, bigger MySQL, probably a second API copy). Railway's Usage page is the truth. One shop's subscription covers the whole platform's Railway bill, so break-even is about **one shop**.

## 2. What a shop pays Meta (not you)

Shops pay Meta directly, with their own card in WhatsApp Manager (your earlier decision). You are not a Meta reseller, so none of this reaches you.

### The rule since 1 October 2026
- Messages the shop's bot sends inside the 24-hour window after a customer writes ("service messages"): **free for the first 1,000 per number per month, then charged.**
- Utility templates (for example a staff alert outside the window or a booking reminder): charged. Inside an open window they were free; from 1 October they are charged too.
- Marketing templates: the most expensive. NeoTalab sends none.
- Customers writing to the shop is always free.
- Lebanon is in Meta's "Rest of Middle East" group. **Rates (per message, from rate-card summaries):** utility and service **$0.0091 to $0.0105**; marketing **$0.0392 to $0.0451**. The two sources I found disagree slightly, so I used the higher number. Your own usage config still assumes $0.0091. **The exact figure is on each shop's WhatsApp Manager billing page**, and the first real shop will show it.

### How many messages one order sends (measured in the lab)
- About **8.7 messages to the customer** per order (menu, cart, checkout steps, receipt, status updates).
- About **3.7 to the shop's team** per order (staff and driver alerts).
- **12.4 in total.** I add about 4.5 for chats that do not end in an order, so **about 17 per order**.

### The shop's monthly Meta bill (at $0.0105; at $0.0091 it is about 13% less)
| Orders a month | Messages | Billable (above 1,000) | **Meta bill** | Per order |
|---|---|---|---|---|
| 60 (2 a day) | 1,050 | 50 | **$0.53** | $0.01 |
| 150 (5 a day) | 2,580 | 1,580 | **$17** | $0.11 |
| 300 (10 a day) | 5,130 | 4,130 | **$43** | $0.14 |
| 600 (20 a day) | 10,230 | 9,230 | **$97** | $0.16 |
| 1,500 (50 a day) | 25,530 | 24,530 | **$258** | $0.17 |

A shop must add a payment method in WhatsApp Manager before it passes 1,000 messages, or Meta will stop delivering the paid ones. Add it to the sign-up checklist.

Other costs for the shop, not Meta: the phone number itself, and the Whish fees on their own side.

## 3. What to charge

Today: **$30 a month, $300 a year, 3-day free trial** (what the plan page shows).

| Option | What it is | For | Against |
|---|---|---|---|
| **A. Keep $30 / $300** | One plan | Simple to explain; already built; cost is nowhere near it | A 60-order shop may find $30 high; a 1,500-order shop gets a bargain |
| **B. Three tiers** | About $20 (up to 100 orders), $35 (up to 500), $70 (more, extra staff and numbers) | Fits small and big shops | More to explain; the order counts are my guess until real data |
| **C. $30 plus a per-order fee above 1,000 orders** | Small usage charge | Heavy shops pay more | Needs order counting and explaining |

**My recommendation: A now.** Launch at $30 with the first 10 to 20 shops. After a month the owner portal's usage page shows each shop's real orders, AI cost and WhatsApp messages. Then decide on B with real numbers. Keep the yearly plan at $300 (two months free): paid-up cash is worth more to you than the discount costs.

Two things to tell shops honestly before they sign: (1) NeoTalab is $30; (2) Meta bills them separately, about **$0.14 per order** once they pass about 57 orders a month. Compared with one person answering WhatsApp all day, the total is small. For busy shops Meta is the larger bill, so say it up front.

## 4. Risks I found while doing this

1. **Railway spending limit is $20.** Your Railway account has a hard compute limit of $20. With 40 workers the projected bill is about **$17 to $20**, and it rises with more shops. If it reaches the limit Railway stops the services. Raising it to about $50 costs nothing unless it is used.
2. **OpenAI balance.** When I last looked (3 Nov, before the 900-customer test) it was **$4.52** with auto-reload off. At 100 shops the AI costs about $2 to $3 a day, so that balance would last about two days and the bot would answer "we could not process your message". Turn on auto-reload or top up $30 to $50 and set an alert.
3. **Meta numbers can differ.** The rates and the "1,000 free" rule come from Meta's page and two rate-card sites that disagree slightly. Confirm in WhatsApp Manager with the first shop.
4. **Messages per order drive the shop's Meta bill.** Shorter flows (fewer menu messages) would cut it directly. Worth a look once real shops give real counts.

## 4b. How to cut the shop's Meta bill
> **Correction (5 November):** the message-saving numbers in section 4b below were optimistic. A careful count of every message (see `docs/MESSAGE-STUDY-2026-11-05.md`) gives, for a typical shop at 300 orders, about **$39 today, $29 after the simple cuts, $25 with lean team and driver messages, $20 with the form checkout** (not $9). Use the new study for those numbers.


Meta charges per message sent, so the only lever is sending fewer. I ran one real button order (delivery shop, two zones) in a test and counted **9 messages to the customer**: greeting list, "how many?", "added", "which area?", "send your location", "add details", "total and cash currency", receipt, confirmed. Add about 3.7 to the team and the non-order chats and it is about 17 per order.

**Correction (4 November):** my first version of this section proposed skipping the currency question. That was wrong. Customers can pay in dollars or in lira (or split), and the checkout asks only when the shop has a lira rate. **The currency choice stays.** The saving comes from merging steps instead.

| # | What | Saves per order | Effort | Notes |
|---|---|---|---|---|
| 1 | Tell shops to advertise with **Click-to-WhatsApp ads** | everything for customers who arrive from an ad (free 7-day window) | none | Meta's page says marketing, utility and service messages inside that window are free |
| 2 | **Merge the receipt with the payment choice.** One list message shows the full receipt (items, delivery, total in dollars and in lira) with rows such as "Confirm, pay in dollars", "Confirm, pay in lira", "Confirm, split", "Edit", "Cancel" (and cash or Whish rows when the shop takes Whish). Tapping a row confirms. | 1 to 2 | small to medium | Keeps the currency choice and the confirm step. A message can hold up to 10 list rows, a button message only 3 |
| 3 | **Merge "send your location" with "add details"**, and skip "which area?" when the pin already gives the area or the shop has one zone | 2 | small | Returning customers already skip address steps |
| 4 | **Default the quantity to 1** with a "change quantity" button on the "added" message | 1 per item | small | |
| 5 | **Customer update level per shop**: minimal (delivered only, with the rating request inside it), normal, full | 1 to 3 | small | Each automatic update is a paid message |
| 6 | **Team alerts to one manager number** by default | 1 to 2 | small | About a fifth of the messages per order go to the team |
| 7 | Greeting nudge: "write your whole order in one message" | 3 to 4 for those who do | tiny | A typed or voice order skips the menu |
| 8 | **WhatsApp Flows or a catalog checkout**: one form message for items, area, address and payment | about 3 to 4 | large (1 to 3 weeks) | Biggest saving; do it after the first real shops show how they order |

**Is this the minimum?** Per order, customer side, with these cuts:
- A new customer who uses the menu buttons: **5 messages** (menu, added, location, receipt and payment, confirmed).
- A customer who types the order: **3 to 4**.
- A returning customer with a saved address who types: **2** (receipt and payment, confirmed).
- Below that only Flows or a catalog get lower, and the shop's own team alerts and delivery updates still add 1 to 3.

What not to do: never send marketing templates (about four times the price of a normal message). Splitting one shop over several numbers to get more free messages is allowed per number but is not a fix.

**What it is worth** (shop's monthly Meta bill, at $0.0105 a message):

| Messages per order, all included | Free until (orders a month) | 150 orders | 300 orders | 600 orders |
|---|---|---|---|---|
| 17 (today) | 57 | $16.6 | $43.4 | $96.9 |
| about 11 (items 2 to 6, currency question kept) | 88 | $7.1 | $24.5 | $59.1 |
| about 9 (customers type or come back) | 107 | $4.0 | $18.2 | $46.5 |
| about 6 (Flows plus returning customers) | 161 | $0 | $8.7 | $27.6 |

Items 2 to 6 together are about three to five days of work and take a 300-order shop from $43 to about $25.

## 5. How I calculated

- **Railway:** your Usage page (memory $0.000231 per GB-minute, about $10 per GB-month; CPU about $0.0005 per vCPU-minute). Average memory last cycle 0.94 GB; the 40 workers add about 0.75 GB (queue memory went from 0.7 GB to 1.5 GB after the change).
- **AI:** 7,771 real calls in the lab: 4,287 input tokens (3,843 of them cached) and 86 output tokens each; $3.16 in total at `gpt-4o-mini` prices. Production's `gpt-6-luna` prices from `backend/config/usage.php`.
- **Messages per order:** the older lab database: 127 orders, 8.7 outbound messages per ordering customer, 466 messages to the team.
- **Meta:** pricing page and rate cards below.

Sources: [Meta pricing page](https://developers.facebook.com/docs/whatsapp/pricing), [Gallabox rate card](https://docs.gallabox.com/pricing-and-billing/whatsapp-pricing/rate-card.md), [Zernio pricing calculator](https://zernio.com/whatsapp-api-pricing).

## 6. What to check with real data after the first month
- Owner portal, Usage page: AI cost and message counts per shop.
- Railway, Usage: the real bill and the spending limit.
- A shop's WhatsApp Manager: the real rate per message and the 1,000 free messages.
- Orders per shop per month: this decides between options A and B.
