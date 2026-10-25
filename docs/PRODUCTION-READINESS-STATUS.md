# Production readiness status

Updated 25 October 2026 (QA checkpoint 9). Verified facts are marked with their evidence; anything
not tested says so. Detail: [MERCHANT-COMPATIBILITY-MATRIX.md](MERCHANT-COMPATIBILITY-MATRIX.md) ·
[AI-SAFETY-EVALUATION.md](AI-SAFETY-EVALUATION.md) · [SECURITY-AUDIT.md](SECURITY-AUDIT.md) ·
[TECHNOLOGY-UPGRADE-AUDIT.md](TECHNOLOGY-UPGRADE-AUDIT.md) · bugs: [CONTINUOUS-QA-BUG-LOG.md](CONTINUOUS-QA-BUG-LOG.md) ·
tests: [CONTINUOUS-QA-TEST-MATRIX.md](CONTINUOUS-QA-TEST-MATRIX.md) · history: [CONTINUOUS-QA-STATUS.md](CONTINUOUS-QA-STATUS.md).

## Verdict

**Not ready for an open public launch. Ready for a controlled pilot** with a few restaurants/cafés
(and, accepting the listed gaps, groceries and salons) **once the three blockers below are cleared.**
The core order engine, tenant isolation and AI safety rules hold under every test run; what is missing
is proof on the live outside services and a tested production restore.

### Blockers before any real customer
0. **Deploy checkpoint 9** (D29): until the migration `2026_10_25_100000_widen_orders_payment_currency` runs in
   production, every cash order paid part in dollars, part in lira fails there. If any shop already took split
   orders, check Owner → Inbox → System for failed checkouts.
1. **Live WhatsApp and Whish never tested end to end** (Meta App Review and access verification pending since 23 Oct). All WhatsApp/Whish behaviour is proven against stand-ins only.
2. **Production backup restore not tested.** The app's own backup → restore procedure now works end to end on MySQL 26.10
   (25 Oct: `backup:run --only-db`, restored into an empty database in 1 s, **all 40 tables byte-identical**, Arabic text,
   kg quantities and split-cash orders intact; steps under "Restore procedure" below). Still not done: restoring a real
   production backup file (the backup storage keys are only in Railway), and the production pairing of MariaDB's
   dump tool with a MySQL server.
3. **Uptime alert not set up** (needs the owner's UptimeRobot account).

### Before a wider launch
- Laravel 12 security support ends **24 Feb 2027**: the upgrade to 13 is **done and tested on branch `upgrade/laravel-13`**
  (515/515 on PHP 8.4 and 8.5, SQLite and MySQL); merge it after a run on a Railway test environment.
- Pin the Node version for the web build (CI uses 22, Railway is unpinned).
- Free-text AI replies are not checked against the data (see AI report).

## Launch criteria

| Criterion | Status | Evidence |
|---|---|---|
| Critical business workflows pass | **pass in lab; live unverified** | order 37/37 (`s05`), pickup/handover (`s08`), bookings (`s07`), Whish journey vs stand-in 20/20 (`s18`). Split cash was broken on MySQL until D29 (25 Oct) |
| All roles and permissions tested | pass | role table in the security audit; `s03` 300+ checks; `AiActionSafetyTest` team isolation |
| Tenant isolation | pass | `*IsolationTest`, `s03`, AI path (`AiActionSafetyTest`) |
| No open critical/high security or data-integrity defect | pass (after deploying checkpoint 9) | D1–D31 all fixed with tests; D29 and D26 were High |
| Core AI actions pass acceptance tests | pass | hostile-model tests in `AiActionSafetyTest`; real model 25 Oct after D30–D31: `s24` 24/24, `s10` 18/18, `s11` 4/4 (before the fixes 23/24, 15/18, 3/4) |
| Pricing and transaction integrity | pass | today's price at checkout, last-item races on MySQL (5 buyers × 10 rounds), size stock (D24), totals in `s24` |
| Automated tests and builds | pass | backend 515/515 on SQLite **and on MySQL 26.10**, Pint clean, web unit 10/10, web build OK (25 Oct). CI now also runs the suite on MySQL 8.4 (`backend-mysql` job: not yet run on GitHub) |
| Dependency and runtime risks documented | done | technology audit |
| Backup and recovery tested | **partial** | app backup + restore verified on MySQL (40/40 tables equal); production files not yet (blocker 2) |
| Integrations classified | done | WhatsApp: simulated · Whish: simulated · OpenAI chat and speech: live-tested in lab · Google travel times: live-tested · email (Resend): unverified this checkpoint |
| Skipped tests and blockers accounted for | done | this file |
| Deployment and rollback plan | documented | below |

**Pass rate of defined automated tests:** 515/515 backend (SQLite and MySQL) + 10/10 web + 46/46 real-AI (`s24`, `s10`, `s11`, last run).
This is the pass rate of the tests that exist, not a measure of how much of the product is covered:
the merchant matrix lists whole workflows (wholesale, multi-branch, digital) that have no tests because they do not exist.

## Performance (measured 23 Oct, not rerun at this checkpoint)
MySQL lab, 500 items, 400 customers, 2,000 orders, 20 calls each: medians menu 43 ms, customers 33 ms,
reports 129 ms, owner shop list 37 ms, WhatsApp "hi" 89 ms with the stand-in AI (the real AI adds 1–4 s per
reply). Large menus are cut to matching items before the AI (`ai.catalog.full_max`). One chat is answered
one message at a time (per-customer lock); other chats run in parallel on several queue workers.
Not measured: hundreds of simultaneous chats on Railway's real resources, OpenAI rate limits at peak.

## Deployment and rollback
- Every push to `main` runs CI (backend tests, Pint, web build). Railway deploys `main`.
- Before deploying: CI green; for migrations, `migrate:refresh` on a MySQL copy.
- Rollback: Railway → service → Deployments → redeploy the previous one. The new migration of this checkpoint
  (`order_items.variant_id`, nullable) is safe to leave in place on rollback, and has a working `down()`.
- Not done yet: a separate Railway staging environment. Recommended before the Laravel 13 upgrade.

## Prioritised roadmap
1. Clear the three blockers (live WhatsApp/Whish test, production restore, uptime alert).
2. Pilot with 2–3 restaurants; watch Owner → Inbox → System daily.
3. Laravel 13 + PHP 8.5 in a staging environment (before Feb 2027); pin Node 24.
4. Lab runs with the real AI for the 24–25 Oct features (kg, size × colour, team bookings, chosen times, quotes).
5. Money-figure check on free-text AI replies; deposits for salons and made-to-order.
(Done since 24 Oct: kg quantities, size × colour stock, stylist choice, customer-chosen times, quotes; medical shops removed.)

## Checkpoint 9 (25 October 2026)
- Baseline: backend 507/507, Pint clean, web unit 10/10, web build OK, `composer audit` / `npm audit` clean.
- Audited what was added after checkpoint 8 (quotes, kg weights, size × colour stock, team bookings, chosen times,
  medical removal). Found and fixed D25–D31 (bug log): two High (D26 quote "1,500" read as 1.50$; D29 split cash
  fails on MySQL), five Medium.
- New: the whole suite runs on MySQL (local 26.10: 515/515; CI job added). Real parallel MySQL races on closing an
  order: 0 wrong in 60 rounds × 2 kinds, with old and new code (D28 rests on the simulated race test).
- Quotes: tenant isolation tested (another shop's staff cannot price a request).

## Checkpoint 10 (25 October 2026, owner-approved list)
- CI run #146 (checkpoint 9) passed, including the new MySQL job. The live API answers (`/up` 200); it migrates on
  start and refuses to start if a migration fails, so the D29 fix is very likely live. **Not confirmed in the Railway
  log** (Railway asks for a sign-in in my browser).
- Production MySQL version and `OPENAI_MODEL`: **not read** (same reason).
- Real AI on the 24–25 Oct features, new suite `qa/s25_new_features_real_ai.py`: **16/16 on two runs** (kg ×3, size ×
  colour stock ×3, stylist choice and no double booking ×3, quotes end to end incl. shop phone price and one order ×6,
  chosen delivery time ×1).
- Laravel 13.38.0 + Tinker 3 + PHPUnit 12 on branch `upgrade/laravel-13`: 515/515 on PHP 8.4.17 and 8.5.8, SQLite and
  MySQL; 53 migrations roll back and re-run; `composer audit` clean. Upgrade-guide items applied: request-forgery
  middleware rename, cache never unserializes objects; checked and not affecting us: cache/session prefixes (already
  set), `upsert`, queue events, joined deletes. Not merged.
- Backup restore procedure verified (above). Not done by me: uptime alert (needs an account in your name), live
  WhatsApp/Whish test (Meta review pending), pilot shops (your choice of shops).

## Restore procedure (tested 25 Oct on MySQL 26.10)
1. Download the newest zip from the backup storage (`BACKUP_S3_*` in Railway) and unzip it: `db-dumps/mysql-<db>.sql`.
2. Create an **empty** database (never restore over the live one while it runs).
3. `grep -v GTID_PURGED db-dumps/mysql-<db>.sql | mysql -h <host> -P <port> -u <user> -p <empty db>`
   (MySQL refuses the `GTID_PURGED` line when the dump came from the same server.)
4. Check: same tables and `CHECKSUM TABLE` per table as the source, or at least row counts of orders, customers, products.
5. Point a copy of the API at it (`DB_DATABASE`) and open the owner portal before switching production over.

## Resume here
- Lab (this checkpoint): API `127.0.0.1:8031`, WhatsApp stand-in `MOCK_GRAPH_PORT=8039`, `export QA_API=http://127.0.0.1:8031/api/v1`,
  queue with the real AI `qa/.lab/lab-ai.sh php artisan queue:work --sleep=0.2 --tries=3` (restart it after code changes).
- MySQL suite: start any throwaway MySQL, then `DB_CONNECTION=mysql DB_SOCKET=<socket> DB_DATABASE=<empty db> … vendor/bin/phpunit`
  (set `DB_SOCKET` explicitly: `backend/.env` points at MAMP's socket).
- Next tasks: watch the first GitHub run of the `backend-mysql` job; run the 24–25 Oct features (kg, size × colour,
  team bookings, chosen times, quotes) through the lab with the real AI; rerun `s13` speed and `s04` races;
  test email delivery (Resend) in the lab; read the production MySQL version and `OPENAI_MODEL`.
