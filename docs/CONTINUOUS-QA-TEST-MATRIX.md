# Continuous QA: test matrix

Method: **A** automated backend test · **L** lab run (real API, queue, signed webhooks, stand-in WhatsApp) ·
**R** real external service · **B** browser (Playwright) · **M** MySQL lab. Simulated integrations are marked as such.

| Feature / workflow | Roles | Method | Result | Evidence | Defect | Remaining gap |
|---|---|---|---|---|---|---|
| Sign-in, sign-out, forged tokens, disabled login | all dashboard roles | A, L | pass | `s01`, `s03`, `AccountGuardTest` | D1, D2 | token expiry after 30 days (backlog) |
| Role permissions on every route | owner, shop owner/admin/staff | L | pass | `s03` | D2 | — |
| Tenant isolation (ids, lists, search, reorder) | 2–7 shops | L, M | pass | `s03` | — | exports: none exist |
| Over-posting | shop owner | L | pass | `s03` | — | — |
| Owner portal lists, settings, secrets hidden | owner | L, B | pass | `s09`, Playwright | — | — |
| Shop creation, join → approve | owner, applicant | L, M | pass | `s01`, join check | D4 | join form abuse (backlog) |
| Menu, sizes, extras, stock, price rules | shop owner | L | pass | `s02` | D3, D7 | menu import (backlog) |
| Delivery order end to end | customer, staff, driver | L | pass 37/37 | `s05` | — | — |
| Pickup, minimum, closed, blocked, handover | customer, staff | L | pass | `s08` | D6 | shop-phone ✅/❌/⏸ (backlog) |
| Bookings and races | salon customers | L, M | pass | `s07` | D4 | — |
| Last item, order numbers under races | customers | L, M | pass | `s04`, MySQL runs | D5 | — |
| Today's price at checkout | customer | A, L | pass | `CheckoutTest` | — | offers expiring mid-cart (backlog) |
| Trial expiry, suspend, reactivate, delete | owner | L | pass | `s09` | — | billing reminders, paid-period stop (backlog) |
| Webhook security, junk input, duplicates | Meta (simulated) | L | pass | `s06` | — | — |
| Whish callback signature | Whish (simulated) | L | pass | `s06` | — | full Whish payment journey on the lab (backlog) |
| Real AI in 4 languages | customer | R (OpenAI) | 18/18, 16/16 | `s10`, `s11` | D8, D9 | — |
| Voice notes | customer | R (OpenAI speech) | 3 langs, 1 misheard computer voice | `s12` | — | — |
| Calls | customer | L | pass | `s12` | D10 | — |
| Driver removal mid-delivery | shop owner, driver | A | pass | `WhatsAppOperationsTest` | D11 | — |
| Daylight saving | shop, customers | A | pass | `DaylightSavingTest` | — | — |
| Speed (500 items, 2,000 orders) | shop, owner | M | pass | `s13` | — | — |
| Migrations undo/redo | — | M | pass | refresh ×2 | D12 | — |
| Account recovery (new login on WhatsApp) | owner, shop | L | pass | `s14` | — | — |
| Browsers: Chromium desktop/phone/tablet, Firefox, WebKit | owner, shop | B | pass | Playwright | — | — |
| Keyboard, accessible names, RTL | owner, shop | B | pass | `accessibility.spec.js` | — | — |
| Google driving times | — | R (Google) | pass | tinker via `TravelTime` | — | — |
| Live WhatsApp, Whish | shop, customer | — | **blocked** | — | — | Meta App Review in progress |
| Sessions: 30-day expiry, 8-hour support session, password change, sign out other devices, owner email change, sign-in brute force | shop owner, owner | L | pass 24/24 | `s15` | — | — |
| Live API from outside: errors, CORS, exposed files, headers | anyone | live (read-only) | pass after fix | curl checks | D14 | — |
| Shop phone ✅ ❌ ⏸ ▶️ (coexistence echoes) | shop phone, customer | L | pass 10/10 | `s16` | — | — |
| Timers: nobody accepts, late order | shop, customer, staff | L (queue) | pass 13/13 | `s17` | — | — |
| Whish journey: connect, exact pay, wrong amount, window ends, late pay → refund, failed pay, double callback, refused order | shop, customer | L, **simulated Whish** (`qa/mock_whish.py`) | pass 20/20 | `s18` | — | live Whish (blocked) |
| Ratings after delivery (5, ٢, no overwrite, typed quantity is not a rating) | customer | L | pass 5/5 after fix | `s19` | D15 | — |
| Delivery areas on the map (circle, drawn shape, smallest wins, outside, bad shapes) | shop owner, customer | L | pass 10/10 | `s19` | — | — |
| Split cash and lira-only, rounding up to 1,000 LL | customer | L | pass 3/3 | `s19` | D16 | — |
| Offers with dates (ended mid-cart, starts tomorrow, today only) | shop owner, customer | L | pass 3/3 | `s19` | — | — |
| Menu import (dollars/lira per row, Arabic, re-import updates, 0.00 stops the file, broken/unsafe files, access) | shop owner, staff, other shop | L | pass 21/21 | `s20` | — | menu photo import (real AI vision) not run |
| Faked visitor address vs sign-in and join limits | anyone | live (48 harmless sign-ins), A | **fixed** | `TrustedProxyTest` | D17 | header layout on Railway unconfirmed |
| Join form abuse (wrong country, bad numbers, hidden bot field, terms, long name, pending duplicate, bad email, 5 per visitor per hour) | applicant | L | pass 13/13 | `s21` | — | the owner portal showing a shop name with HTML: not checked in a browser |
| WhatsApp flood limit (40 per 10 min) and blocked numbers | customer | L | pass | `s22` | — | — |
| Problem alerts (recorded, secrets hidden, marked fixed, shops can't see them) | owner, shop | L | pass 4/4 | `s22` | — | — |
| Billing reminders (3 days before, 1 day for a trial, once a day, template or 24-hour window) | shop owner | A | pass (existing tests) | `WhatsAppOperationsTest` | — | — |
| Paid period ends → shop stops after the grace period; trial ends → stops | owner, shop | A | pass (existing tests) | `SubscriptionBillingTest`, `WhatsAppOperationsTest` | — | — |
| Nightly report figures against the database (orders, sales incl. lira, rating, late, top items, quiet day) | shop owner | A | pass 2/2 | `ReportFiguresTest` | — | — |
| Shop staff cannot use the dashboard | shop staff | A | pass 2/2 | `StaffDashboardTest` | — | — |
| Staff type "refunded <number>" to close a Whish refund | shop staff | A | pass | `WhatsAppOperationsTest` | — | — |
| Driver "Not delivered": order closes, driver freed, customer and shop told | driver, shop, customer | A | pass 2/2 | `WhatsAppOperationsTest` | — | — |
| Forgot password: email link, unknown email, staff get nothing, link works once, bad link refused | owner, shop | A | pass 5/5 | `PasswordResetTest` | — | website page: build blocked (see status) |
| Shop's real email becomes its login (join approval; owner can set it) | owner | A | pass 2/2 | `AdminMerchantManagementTest` | — | — |
| Backup restore (dump → restore → compare 7 tables) | — | M | pass | manual dump with GTID off, 7 tables equal | — | the app's own backup command: fails on this computer (client version); production client unverified |
| Menus by shop type (9 kinds: restaurant, grocery, pharmacy, retail, flowers, water, laundry, services, other): hand-built menu, sizes, extras, units, CSV upload with lira row, WhatsApp menu and ordering | shop owner, customer | L (real WhatsApp stand-in) | pass 120/126 checks: 8 of 9 kinds fully pass; services 12/14 (2 gaps) | `s23_menus_by_type.py` | lira row without rate saved as dollars (fixed, `MenuImportTest::test_lira_rows_are_refused_when_the_shop_has_no_lira_rate`) | services: CSV cannot set duration; greeting does not list services |
| Menu photo import (AI reads a jpg) | shop owner | R (OpenAI, real call) | pass: 4 of 4 items read; lira price read without the "LL" mark, so it appears as dollars in the preview | lab run | — | the preview must be checked by the shop |
| Hostile AI output (other shop, switched-off extras, group limits, quantities, other customer's cart, fake checkout fields) | customer, attacker | A | pass 8/8 after fixes | `AiActionSafetyTest` | D18–D21 | — |
| Real AI attacks: prompt disclosure, fake discounts and payments, other shop/customer data, fake system message, invented fees/stock, "place it now", languages, bookings | customer, attacker | R (OpenAI) | 24/24 ×2 | `s24` | D21, D23 | free-text replies are not checked against data |
| Booking cancel with several bookings; staff told | salon customer, staff | A | pass | `AppointmentTest` | D22 | staff told inside 24 h window only |
| Stock per size (take, sold out, give back on cancel) | customer, shop | A | pass | `AiActionSafetyTest` | D24 | — |
| Another shop's staff and drivers tapping this shop's order buttons | staff, driver | A | pass | `AiActionSafetyTest` | — | — |
