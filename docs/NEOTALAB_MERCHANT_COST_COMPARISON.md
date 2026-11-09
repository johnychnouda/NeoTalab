# Cost comparison for a merchant: human, basic bot, NeoTalab with GPT-6 Luna (9 November 2026)

Prices checked on 9 Nov 2026. Nothing here changes NeoTalab's price ($30 a month, $300 a year); it is used as it stands.

## Sources
- OpenAI price page (gpt-6-luna: $0.10 in, $0.01 cached, $0.50 out per million tokens): https://developers.openai.com/api/docs/pricing
- Meta pricing page (billing per delivered message; 1,000 free service messages per number per month; from 1 Oct 2026 service messages above that are charged; Lebanon is in "Rest of Middle East"): https://developers.facebook.com/docs/whatsapp/pricing
- Meta's page does not print the Lebanon rate. The rate used, **$0.0105 per message** (service and utility, "Rest of Middle East", effective 1 Oct 2026), comes from a partner's copy of the rate card: https://docs.gallabox.com/pricing-and-billing/whatsapp-pricing/rate-card.md . **Next step to confirm:** open the first shop's WhatsApp Manager > Billing and read the rate there, or download Meta's rate-card CSV linked from the pricing page.

## What was measured, and what is assumed
| Item | Value | Basis |
|---|---|---|
| AI calls for one full typed order | 3 | measured, real GPT-6 Luna, lab |
| AI cost of that order | $0.00041 | measured |
| AI cost per call (average of 49 real calls) | $0.000165 | measured |
| Messages sent to the customer per ordering chat | 8 | measured (pickup, cash) |
| Messages for a chat that does not order | unknown | assumed to be no more than 8 |
| Human time per chat | 4 minutes | **assumption**, replace with the shop's own number |
| Human hourly cost | $3 | **placeholder**, not sourced; replace |

A "conversation" below means one customer chat that ends with an answer or an order, on one shop number in one month. All lines assume 8 messages, which overstates the fee for chats that do not order.

## The three setups
- **A. Human on Meta:** the shop owner or an employee answers in WhatsApp. No AI. Whether replies typed in the WhatsApp Business app are billed is not stated on Meta's page; it is expected to be free but is not confirmed here. If a person answers through the API, the Meta fee in column B applies.
- **B. Basic bot (rules, no AI):** Meta fees only, as in the table. The bot vendor's subscription is not priced (no vendor chosen).
- **C. NeoTalab + GPT-6 Luna:** same Meta fee as B, paid by the shop to Meta, plus the AI cost (paid by NeoTalab, inside the $30), plus the $30 subscription.

## Monthly cost for one shop (USD)
| Conversations | A. Human labor (4 min, $3/h) | B. Meta fee, bot | C. Meta fee | C. AI (3 to 8 calls) | C. NeoTalab fee | **C. total to the shop** |
|---|---|---|---|---|---|---|
| 100 | 20 (6.7 h) | 0 | 0 | 0.04 to 0.13 | 30 | **30** |
| 500 | 100 (33 h) | 31.50 | 31.50 | 0.20 to 0.66 | 30 | **61.50** |
| 1,000 | 200 (67 h) | 73.50 | 73.50 | 0.41 to 1.32 | 30 | **103.50** |
| 5,000 | 1,000 (333 h) | 409.50 | 409.50 | 2.05 to 6.60 | 30 | **439.50** |
| 10,000 | 2,000 (667 h) | 829.50 | 829.50 | 4.10 to 13.20 | 30 | **859.50** |

The shop's total in C excludes the AI line because NeoTalab absorbs it (the AI is under $14 even at 10,000 chats, so it does not change the $30 plan). Column A uses a placeholder wage; at 5,000 chats a single person cannot answer them all, which is the real problem with A.

## What this shows
1. **GPT-6 Luna is not a cost problem.** At 10,000 chats the AI is at most about $13 a month. Meta's per-message fee is 60 to 200 times larger.
2. **Meta's fee is the real driver** and it is the same for a basic bot and for NeoTalab. Fewer messages per order is the only lever (see `docs/MESSAGE-STUDY-2026-11-05.md`).
3. **Above roughly 125 conversations a month** (1,000 free messages / 8) the shop starts paying Meta.
4. Compared with a human, NeoTalab is cheaper from about 150 conversations a month at the placeholder wage. Below that, a person is cheaper on paper, but cannot answer at night or in parallel. This depends entirely on the wage, which is not known.

## Risks
- The 8 messages per chat is measured on one order type. Delivery orders, rating, and status questions add messages; measure on a real shop in its first month (Owner portal > Usage).
- If Meta's real Lebanon rate differs from $0.0105, scale column B and C Meta fees by the ratio.
- The OpenAI balance must be topped up or the assistant stops answering; set auto-reload.
