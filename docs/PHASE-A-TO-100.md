# NeoTalab → 100/100: Phase A (started 9 November 2026)

Path: **A (launch-ready) → B (product excellence) → C (push to 100)**.  
This file tracks **Phase A only**. Phases B and C stay parked until A is done.

Score target after A: **~88–90 / 100** (safe for ~10–20 watched shops).

## Status legend
- `[ ]` not done
- `[~]` in progress / needs owner click
- `[x]` done

## A checklist

### Code / lab (agent)
- [x] Rewrite stale `qa/s05_journey.py` (staff removal + undefined vars + free busy lab drivers)
- [x] Rewrite stale `qa/s16_shop_phone.py` (pin-for-delivery / shop-phone echoes)
- [x] Re-run against local QA lab: **s16 11/11 PASS**, **s05 33/33 PASS** (9 Nov 2026)
- [x] Document how to run scale/attack scripts once (below)
- [x] Chat-100: shop order tickets + checkout unstick (branch `audit-full-operation`, commit `94c9cf3`) — **merge to `main` to deploy**

### Live proof (owner — you click; agent guides)
- [x] **A0 Deploy** — merged `audit-full-operation` → `main` (PR #1, 9 Nov 2026)
- [x] **A1 Live WhatsApp E2E** — AlmalikaWalAmir order **#4** (9 Nov 2026)
- [x] **A2 Live Whish E2E** — AlmalikaWalAmir (9 Nov 2026)
- [ ] **A3 Meta templates** ← **do this next**
- [ ] **A4 Production backup restore** (repeat with real shop data when shops exist; mechanism proven 30 Oct)
- [ ] **A5 Uptime** — UptimeRobot on site + API `/up`
- [ ] **A6 Money limits** — OpenAI auto-reload + Railway spend cap
- [ ] **A7 Watch week** — Inbox → System daily; read first ~100 real chats

---

## Scale / attack scripts (lab only — never production)

Needs a **separate** MySQL database `neotalab_qa_scale` (default port **3399**), API on **8012**, mock Meta on **8049**. Env already lives in `qa/.lab/scale.env`.

```bash
# 1. Create DB (once)
mysql -h 127.0.0.1 -P 3399 -u root -e "CREATE DATABASE IF NOT EXISTS neotalab_qa_scale;"

# 2. Migrate / seed scale lab (follow prior scale runs: s40 then seed helpers)
# Start mock Meta + API + queue against scale.env (ports 8049 / 8012)

# 3. Run via wrapper so scripts refuse anything that is not the scale lab
cd qa
./scale_env.sh python3 s40_scale_shops.py
./scale_env.sh python3 s41_isolation.py
./scale_env.sh python3 s42_concurrency.py
./scale_env.sh python3 s43_load.py
# Optional / heavier:
./scale_env.sh python3 s48_security.py
./scale_env.sh python3 s50_role_attacks.py
# Real AI (costs money — needs OPENAI in scale.env / lab-ai):
# ./scale_env.sh python3 s44_real_ai_scale.py
```

Prior results: `docs/SCALE-TEST-2026-11-03.md`, `docs/FINAL-REPORT-2026-11-03.md`.  
Phase A does **not** require a full re-run unless you change concurrency/checkout; documenting this closes the readiness gap “never told how.”

---

## Owner runbook (do in order)

### A1 — Live WhatsApp E2E (next)

**Goal:** one real customer message → real AI reply → real order → shop/driver finish on a real number.

1. Open [Owner portal](https://neotalab.com/owner) → Inbox → **System** → Launch checklist: AI, WhatsApp, queue, scheduler should be green.
2. Pick a test shop with WhatsApp **Connected / Running** (or connect one via Embedded Signup + coexistence).
3. From a **personal** phone (not the shop number), WhatsApp the shop:
   - Say hi → menu / greeting
   - Order something simple
   - Prefer **pickup** first (fewer steps), then one **delivery** with a real location pin
4. Confirm the order (buttons).
5. On the **shop phone** (Business app), in that chat type:
   - `✅` to accept (if auto-accept is off)
   - `ready` / `out` / `done` as needed  
   Or use a driver phone: `start` → Picked up → Delivered.
6. Reply here with: shop name, order number, pickup or delivery, and whether anything failed.

**Pass:** order exists in DB / backoffice analytics sense, customer got status messages, no silence.

### A2 — Live Whish E2E

1. Shop Settings: Whish number set.
2. Customer places a **Whish** order (small amount).
3. Shop types `✅` if waiting for accept → customer gets pay instructions.
4. Customer transfers in Whish app (order # in note) → taps **I paid**.
5. Shop checks Whish → types `✅` in the chat (or ❌ if not found).
6. Owner portal: record one **subscription** payment with a real Whish reference (unique).

**Pass:** order `paid` + confirmed (or rejected cleanly); subscription shows paid.

### A3 — Meta templates

1. Railway API console: `php artisan templates:sync` (or wait for hourly job).
2. Meta WhatsApp Manager: confirm critical templates **APPROVED** (platform welcome/code/alerts; shop billing + customer `neotalab_c_*` for that shop).
3. Owner → Inbox → System: no flood of “customer was not told” template warnings.

### A4 — Backup restore

Already proven once (30 Oct) on empty prod. When shops exist, repeat:

1. Newest zip from R2 / Railway backups disk.
2. Unzip → `db-dumps/mysql-….sql`
3. Restore into an **empty** throwaway DB (never over live):
   `grep -v GTID_PURGED … | mysql … --skip-ssl-verify-server-cert` (if MariaDB client on Railway).
4. Compare table list / row counts (orders, customers, products).

Detail: `docs/PRODUCTION-READINESS-STATUS.md` → Restore procedure.

### A5 — Uptime

[UptimeRobot](https://uptimerobot.com) (free):

1. Monitor HTTP(S) `https://neotalab.com`
2. Monitor HTTP(S) `https://YOUR-API-DOMAIN/up` (Railway API domain from deploy)
3. Alert email/SMS to you

### A6 — Money limits

1. [OpenAI](https://platform.openai.com) → Billing: auto-reload **on**, monthly hard limit set.
2. [Railway](https://railway.app) → project usage: spending limit set (so a loop cannot burn quietly).

### A7 — Watch week

Daily: Owner → Inbox → System.  
Read first ~100 real chats by hand. Note AI mistakes, silence, wrong prices.

---

## Done when

A real order + real Whish worked, restore known, uptime + spend limits on, s05/s16 green (done), first shops watched.

## After A

Phase B: AI money guard, Whish match help, staging, real-AI load on Railway, empty-cart hunt, onboarding polish.
