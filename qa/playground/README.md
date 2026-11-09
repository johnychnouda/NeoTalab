# NeoTalab WhatsApp playground

Interactive lab that exercises the **same WhatsApp surfaces as production**: customer, shop phone, and driver. Fake Meta Graph + lab SQLite — never production.

Open http://127.0.0.1:8025/

## Start

```bash
cd qa
./start_playground.sh
```

Seeds shops, menus, then `seed_playground.py` (Whish, LBP, pickup, auto-accept variants, staff-check, kg, retail days).

## Scenarios (production paths)

| Scenario | What it covers |
|----------|----------------|
| Restaurant — manual + driver + Whish | auto_accept OFF, sizes/extras, delivery/pickup, Whish, LBP, driver buttons, staff-check Arak, rating |
| Bakery — pickup + auto-accept | production default auto_accept, pickup only, shop ready→done |
| Grocery — self-deliver or driver | zones, kg items, shop out/done or driver start |
| Retail — days timing | working-days ETA, color variants |
| Butcher / produce | kg + hours timing, self-delivery |

## Tools in the UI

- Customer chips + free text  
- Beirut pin / out-of-zone pin  
- Photo (bot pauses) / Voice (polite fail without fixture) / Call  
- Seed cart (bypass Fake-AI size friction)  
- Shop: ✅ ❌ ready out done delay refunded ⏸ ▶️  
- Driver: start/stop + tap Picked up / Outside / Delivered / Failed  
- Shop mode: auto / busy / manual / closed  
- Coverage panel: full production WhatsApp checklist  

## Real AI (human voice)

In `qa/.lab/lab.env`:

```env
AI_DRIVER=openai
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-6-luna
```

Restart lab API + queue after editing.

## Not in this playground (by product design)

Web order board / staff dashboard ops — production shops run day-to-day **only on WhatsApp**. Owner portal and menu setup stay on `localhost:3001` / lab web if you need them separately.
