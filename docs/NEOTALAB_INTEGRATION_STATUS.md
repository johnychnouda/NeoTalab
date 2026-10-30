# NeoTalab integration status (30 October 2026)

Classes: **Live** = tested against the real service · **Sandbox** · **Simulated** = tested against a stand-in or fake ·
**Not configured** · **Not implemented**. Nothing below is marked Live unless it says what I saw.

| Integration | Class | Evidence and gaps |
|---|---|---|
| Meta WhatsApp Cloud API (customer and staff messages, webhook) | **Simulated** | Lab stand-in (`qa/mock_graph.py`), signature checked in `s06`. Meta App Review and access verification pending since 23 Oct. Real media, rate limits, number suspension: untested |
| WhatsApp message templates (`neotalab_payment_check`, login code, others) | **Not approved by Meta** | submitted by `templates:sync`; without approval a shop outside the 24-hour window gets no payment alert and no login code over WhatsApp (the app shows the owner the code instead) |
| Whish payments (customer to shop) | **Not an integration** | removed 29 Oct. Customer pays the shop's Whish number by hand; the shop checks its Whish account and taps Received. Simulated message flow only |
| Whish payments (shop to NeoTalab) | **Not an integration** | owner checks the NeoTalab Whish account and records the reference. Tested through the API (`s26`, `SubscriptionPaymentTest`) |
| OpenAI chat and speech | **Live** (lab, paid calls) | production model `gpt-6-luna`: 62/62 on 27 Oct. Not rerun today. Provider outage and rate limit behaviour: only tested with a failing fake (`s14` recovery) |
| Google Maps (travel times) | Live (23 Oct) | not rerun |
| Email (Resend) | **Not verified** | `MAIL_MAILER=log` in the lab; the forgot-password mail was never sent through Resend by me |
| MySQL 9 (production) | Live (read 25 Oct) | local MySQL 26.10 suite passed; production restore untested |
| Queue (database driver, 20 workers) | Live (owner saw 20 workers, 27 Oct) | load test: 1000 customers × 3 messages in ~3 s |
| Backups (app command to S3-compatible storage) | **Partly** | ran nightly 19 to 25 Oct per the scheduler; files never inspected; restore proven on a local copy only |
| Railway deploy | Live (read 25 Oct) | I have not re-read the console today; whether the three new migrations ran is unconfirmed |
| Sentry | Not configured in the lab | not verified in production |
| Uptime monitoring | **Not configured** | owner action |
| File storage for uploads | Not implemented as a feature | only the menu import file is accepted (`s20`) |
| Payment cards, Stripe and similar | Not implemented | not planned: Whish and cash only |
