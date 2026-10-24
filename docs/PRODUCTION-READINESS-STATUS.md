# Production readiness status

Updated 24 October 2026 (QA checkpoint 8). Verified facts are marked with their evidence; anything
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
1. **Live WhatsApp and Whish never tested end to end** (Meta App Review and access verification pending since 23 Oct). All WhatsApp/Whish behaviour is proven against stand-ins only.
2. **Production backup restore not tested.** A dump/restore was checked locally (7 tables equal), but the app's own backup command fails on this computer (client version) and has not been restored from production files.
3. **Uptime alert not set up** (needs the owner's UptimeRobot account).

### Before a wider launch
- Laravel 12 security support ends **24 Feb 2027**: upgrade to 13 (plan in the technology audit).
- Pin the Node version for the web build (CI uses 22, Railway is unpinned).
- Pharmacy and clinic safeguards (prescription-only flag; privacy review for health details).
- Free-text AI replies are not checked against the data (see AI report).

## Launch criteria

| Criterion | Status | Evidence |
|---|---|---|
| Critical business workflows pass | **pass in lab; live unverified** | order 37/37 (`s05`), pickup/handover (`s08`), bookings (`s07`), Whish journey vs stand-in 20/20 (`s18`) |
| All roles and permissions tested | pass | role table in the security audit; `s03` 300+ checks; `AiActionSafetyTest` team isolation |
| Tenant isolation | pass | `*IsolationTest`, `s03`, AI path (`AiActionSafetyTest`) |
| No open critical/high security or data-integrity defect | pass | D1–D24 all fixed with tests |
| Core AI actions pass acceptance tests | pass | 8/8 hostile-model tests; real model 24/24 ×2 (`s24`), 18/18 and 16/16 (`s10`, `s11`) |
| Pricing and transaction integrity | pass | today's price at checkout, last-item races on MySQL (5 buyers × 10 rounds), size stock (D24), totals in `s24` |
| Automated tests and builds | pass | backend 471/471, Pint clean, web unit 10/10, web build OK (24 Oct) |
| Dependency and runtime risks documented | done | technology audit |
| Backup and recovery tested | **partial** | local restore only (blocker 2) |
| Integrations classified | done | WhatsApp: simulated · Whish: simulated · OpenAI chat and speech: live-tested in lab · Google travel times: live-tested · email (Resend): unverified this checkpoint |
| Skipped tests and blockers accounted for | done | this file |
| Deployment and rollback plan | documented | below |

**Pass rate of defined automated tests:** 471/471 backend + 10/10 web + 24/24 real-AI (last two runs).
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
4. Pharmacy prescription-only flag; privacy wording for clinics.
5. Decimal quantities (kg); customer-chosen delivery time; stylist choice in bookings.
6. Money-figure check on free-text AI replies.

## Resume here
- Lab: `qa/setup_lab.sh`, then the processes in [QA-RECORD-2026-10-22.md](QA-RECORD-2026-10-22.md); if ports
  8011/8019 are taken, run the API on 8031 and `MOCK_GRAPH_PORT=8039 python3 qa/mock_graph.py …`, set
  `WHATSAPP_GRAPH_BASE`/`APP_URL` in `qa/.lab/lab.env` to match and `export QA_API=http://127.0.0.1:8031/api/v1`.
- Done: the D24 migration on MySQL 26.10 (up, refresh, rollback removes the column, up again).
- Next tasks: rerun `s13` speed and `s04` races with
  size stock; test email delivery (Resend) in the lab; read the production MySQL version and `OPENAI_MODEL`.
