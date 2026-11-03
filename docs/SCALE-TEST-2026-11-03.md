# Scale test, 2–3 November 2026

Own lab, never production: MySQL on this Mac, API on 8012, mock Meta WhatsApp on 8049, 10 queue workers, fake AI (no AI cost in this part).
Scripts: `qa/s40_scale_shops.py`, `scale_seed.php`, `scale_hours.php`, `scale_tokens.php`, `s41_isolation.py`, `s42_concurrency.py`, `s43_load.py`; run with `qa/scale_env.sh`.

## Data
120 shops (20 kinds, English/Arabic/French), 10,600 products (15 to 500 per shop), 1,500 customers (some phones are customers of two shops), 600 staff, 50,040 orders with 125,040 lines.

## Results
| Question | Result |
|---|---|
| Shops cannot reach each other | 1,680 attacks by id (read, edit, delete products, categories, zones, customers, staff, conversations): all refused (404), victim data byte-for-byte unchanged. Lists hold only own rows (31 shops). A shop login got 403 on 96 attempts at owner pages and actions. Forced `merchant_id` in body and headers ignored. No login: 401. Bad webhook signature refused. Same phone in two shops: nothing created in the other shop. |
| Simultaneous orders, one shop | 100 of 100, 300 of 300, 750 of 750 orders saved. No customer got two orders, no order number repeated, every line priced from the catalog, every total adds up. Slowest order saved after 18 s at 750 at once (p50 9 s). |
| Last item | 50 in stock, 300 customers want 1: exactly 50 orders, stock ends at 0. |
| 120 shops at once | 576 customers (the orders-type shops), 576 orders, each in the right shop, no mix. |
| Load (mixed customers + dashboards, this laptop) | 10, 50, 100 users: 0 errors, p95 under 330 ms, queue empty at the end. 250 users: about 88 requests/s is the ceiling, p95 2.7 s, 1.55% timeouts. |

## Bug found (D43)
Confirming together in one shop deadlocked on MySQL (`max(number) for update` locks a gap): 19 of 100 orders were lost, the customer got the "could not process" reply. Fix in `CheckoutService`: lock the shop row for the next number, and retry a transaction 3 times on a deadlock. After the fix: 0 deadlocks in 1,150 simultaneous orders. SQLite (the unit tests) cannot show deadlocks; `s42_concurrency.py` on MySQL is the guard.

## Test mistakes I made and corrected (not app bugs)
Customers of appointment shops cannot "add to cart" (139 empty carts); a stock test left the first item at 0 (63 "no longer available"). Both fixed in the script.

## Not yet done
Real-AI batches (thousands of conversations), Railway staging copy and capacity, load levels above 250 on a production-like server, failure and recovery tests, OWASP/scanner pass. The numbers above come from a laptop with the PHP dev server and fake AI, so they say the logic holds at this volume, not what Railway can carry.

## Real AI, first sample (60 customers, 96 orders-type shops, EN/AR/FR)
Not a pass. 5 of 60 customers asked for "2 X" where the shop also sells "X Large" or "X Small" as separate items, and the assistant put the Large/Small item in the cart (catalog price, never an invented price, but not the item asked for). The other 3 "failures" were my checker mistaking a cart total for a price (corrected in `s44_real_ai_scale.py`). No cart held another shop's item. This is open as D44: when the customer's words match one item exactly, that item should win over a longer name. Not fixed yet, and the sample is too small to give a rate.
