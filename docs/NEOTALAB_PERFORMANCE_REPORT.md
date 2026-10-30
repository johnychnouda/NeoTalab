# NeoTalab performance report

Updated 30 October 2026. Everything here is from a laptop lab, not from Railway under real load.

## Measured 30 Oct (MySQL 26.10 lab, `qa/s13_speed.py`)
Dataset: 513 items, 450 customers, 2,016 orders (my own seed; the 23 Oct set was similar but not identical). 20 calls each.
| Call | Median | 95% under |
|---|---|---|
| Shop menu list | 46 ms | 54 ms |
| Shop customers list | 38 ms | 42 ms |
| Shop reports | 103 ms | 107 ms |
| Setup checklist | 33 ms | 37 ms |
| Owner shop list | 39 ms | 44 ms |
| Owner usage | 38 ms | 43 ms |
| WhatsApp "hi" (stand-in AI) | 20 ms | 24 ms |
The real AI adds 1 to 4 s per reply (earlier measurement).

## Stock races on MySQL, 30 Oct
Last item, 2 buyers × 10 rounds and 5 buyers × 6 rounds: an order every round, never oversold.

## Dashboard request volume, 30 Oct (browser, SQLite lab)
A logged-in shop dashboard makes 2 to 4 API calls per page load and about 12 calls a minute when left open on the home
page. The API limit is 120 a minute per user, so five open tabs use half of it. The 429 errors seen in my browser suite came
from the suite clicking quickly, not from normal use.

## Measured earlier, not rerun
- 27 Oct: production queue raised to 20 workers after a load test: 1000 customers × 3 messages answered in about 3 s (stand-in WhatsApp).
- Closing one order from two sides at once: 0 wrong in 60 rounds × 2 kinds (MySQL).
- One chat is answered one message at a time (per-customer lock); other chats run in parallel.

## Not measured
- Hundreds of simultaneous chats on Railway's real resources; OpenAI rate limits at peak.
- Memory and CPU on Railway.
- Thousands of products with the real AI.
- Database connection failures and deadlocks beyond what the race tests show.
