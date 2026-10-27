# Technology and upgrade audit

Checked 24 October 2026 against official sources (links at the end). Nothing was upgraded in this
checkpoint: every row below is a recommendation unless it says "done".

## Summary

| Area | In use | Latest checked | Status | Recommendation |
|---|---|---|---|---|
| PHP (production) | 8.5.12 (`dunglas/frankenphp:1-php8.5.12-alpine`, CI 8.5) | 8.5 | 8.5: active support to 31 Dec 2027, security to 31 Dec 2029 | **Done 2026-10-27:** moved from 8.4 to 8.5.12 (the PHP2Shell fix); all 515 tests pass on 8.5 |
| `composer.json` PHP constraint | `^8.4` | — | ok | **Done** with Laravel 13 |
| Laravel | 13.39.0 | 13.39.0 | latest (12 lost bug fixes on 13 Aug 2026, security fixes on 24 Feb 2027) | **Done on branch `upgrade/laravel-13` (25 Oct)**: 13.38.0, Tinker 3.0.2, PHPUnit 12.5.40, PHP `^8.4`; 515/515 on PHP 8.4/8.5 × SQLite/MySQL. Merged into `main` 25 Oct (owner's decision, no separate test environment); CI #148 green. **27 Oct:** 13.39.0 with all packages at their latest |
| Sanctum, permission, activitylog, sentry, openspout, resend | 4.3.3, 8.3.2, 5.1.3, 4.29.0, 5.13.0, 1.17.0 | same | latest, no advisories | **Done 27 Oct** (`composer update`, 515/515) |
| Composer advisories | — | `composer audit`: none | ok | — |
| Node.js | local 24.11.1; CI and Railway web pinned to 24 (`engines` `24.x`, 27 Oct) | 24.22.1 (LTS "Krypton"), 26 (Current) | 24 LTS | **Done 27 Oct**: `"engines": {"node": "24.x"}` in `web/package.json`, CI `node-version: '24'`. Local Mac: update to 24.22.1 from nodejs.org |
| Next.js | 16.4.3 | 16.4.3 | latest; critical advisory of 16.4.0 fixed at checkpoint 7 | stay |
| React / React DOM | 19.3.1 | 19.3.1 | latest | **Done 27 Oct** |
| Vitest, Playwright | 5.0.5, 1.64.1 | 5.0.5, 1.64.1 | latest | **Done 27 Oct** |
| npm advisories | — | `npm audit`: 0 | ok | — |
| PHPUnit | 13.4.5 | 13.4.5 | latest | **Done 27 Oct**: 515/515 on SQLite and MySQL |
| MySQL | Railway MySQL 9 (`mysql:9`, read 25 Oct) | — | ok; CI runs the suite on `mysql:9` | The backup client in the image is MariaDB's `mysqldump`: the backup must be test-restored from production files (see readiness) |
| AI model | production `OPENAI_MODEL` = `gpt-6-luna` (read 25 Oct) | — | ok; real-AI checks 62/62 on it (27 Oct) | Rerun `qa/s24`, `s10`, `s11`, `s25` after any model change |
| WhatsApp Cloud API, Whish | Graph API via `WHATSAPP_GRAPH_BASE` | — | live tests blocked by Meta App Review | — |

## Laravel 12 → 13 plan

1. Branch; `composer require laravel/framework:^13.0 laravel/tinker:^3.0 phpunit/phpunit:<version Laravel 13 requires> --with-all-dependencies`.
2. Follow the official upgrade guide (laravel.com/docs/13.x/upgrade) item by item; check each first-party
   and spatie package has a 13-compatible release.
3. PHP: Laravel 13 needs 8.3+; upgrade the image to `php8.5` in the same branch only if tests pass on 8.5 (they do today on 12).
4. Tests: full backend suite, Pint, `migrate:fresh` + `migrate:refresh` on MySQL, the lab suites
   `s01`–`s24`, Playwright on desktop and phone.
5. Deploy to a separate Railway environment first (copy of production data without customer phones),
   then production. Rollback: redeploy the previous image in Railway; migrations in that upgrade must have working `down()`.

## Sources (official)

- PHP supported versions: https://www.php.net/supported-versions.php
- Laravel release notes and support policy: https://laravel.com/docs/master/releases
- Laravel 13 upgrade guide (read 25 Oct 2026): https://laravel.com/docs/13.x/upgrade
- Node.js releases: https://nodejs.org/en/about/previous-releases
- Package versions: `composer outdated --direct`, `npm outdated`, `npm view next version` (registry data, 24 Oct 2026)
- Advisories: `composer audit`, `npm audit` (both clean, 24 Oct 2026)
