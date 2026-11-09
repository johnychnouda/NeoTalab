# NeoTalab production readiness (9 November 2026)

## Verdict
Ready for a **small, watched launch** (the first 10 to 20 shops). Not ready to promise anything about thousands of shops. This is an opinion from the tests listed in `docs/NEOTALAB_FULL_OPERATION_AUDIT.md`, not a guarantee.

## What is solid (tested)
- 623 backend tests pass; no cross-shop data leak found; role rules hold on every route; the assistant cannot change prices or fees.
- Orders end to end with the real AI: 3 AI calls, about $0.0004.
- The AI model is verified as `gpt-6-luna` and priced from OpenAI's page.

## Before real shops (do these)
1. **Take the database backup, then deploy.** The 8 to 9 Nov release deletes old data (staff, bookings, stock). See `deploy/STEP-BY-STEP.md`.
2. **Confirm Meta's Lebanon rate** in WhatsApp Manager (the report uses $0.0105 from a partner's copy of the rate card).
3. **OpenAI balance with auto-reload**, or the assistant stops.
4. **Railway spending limit** (was $20 in the 4 Nov study; it stops the service when reached).
5. **Run the scale and attack scripts** (s40 to s50) against a local scale database once. They were not part of this audit.
6. Repair `qa/s05_journey.py` and `qa/s16_shop_phone.py`.

## Known limits
- Whish payment check is manual (the shop types ✅).
- Meta's per-message fee, not AI, is the shop's main running cost (see `docs/NEOTALAB_MERCHANT_COST_COMPARISON.md`).
- Four business types only; no bookings or staff.
- No live latency numbers.

## Watch in the first month
Owner portal > Usage (orders, AI cost, messages per shop), Railway usage, OpenAI balance, failed jobs, and the first 100 real conversations read by a person.
