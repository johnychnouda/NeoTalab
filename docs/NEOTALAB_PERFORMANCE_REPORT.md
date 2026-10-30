# NeoTalab performance report

**Nothing was measured in this session.** The figures below are from earlier checkpoints and were not rerun on today's code.
I am not inventing new ones.

## Measured earlier
- 23 Oct, MySQL lab, 500 items, 400 customers, 2,000 orders, 20 calls each. Medians: menu 43 ms, customers 33 ms,
  reports 129 ms, owner shop list 37 ms, WhatsApp "hi" 89 ms with the fake AI. The real AI adds 1 to 4 s per reply.
- 27 Oct, production queue raised to 20 workers after a load test: 1000 customers × 3 messages each answered in about 3 s
  (see the memory note on workers; this was with the stand-in, not live WhatsApp).
- Stock races (last item, 5 buyers × 10 rounds) on MySQL: never oversold. Closing an order at the same moment from two sides:
  0 wrong in 60 rounds × 2 kinds.
- One chat is answered one message at a time (per-customer lock); other chats run in parallel.

## Not measured
- Hundreds of simultaneous chats on Railway's real resources, and OpenAI rate limits at peak.
- Requests per dashboard session against the 120 per minute per user API limit (browser suites do hit it, see the security audit).
- Thousands of products with the real AI; large reporting periods beyond the 2,000-order dataset.
- Database connection failure and deadlock behaviour beyond what the race tests show.
- Resource use (memory, CPU) on Railway.

To refresh: start the MySQL lab, run `qa/s13_speed.py`, `qa/s04_stock_race.py`, `qa/s04b_stock_race5.py`.
