# Technology and upgrade audit

Checked 24 October 2026 against official sources (links at the end). Nothing was upgraded in this
checkpoint: every row below is a recommendation unless it says "done".

## Summary

| Area | In use | Latest checked | Status | Recommendation |
|---|---|---|---|---|
| PHP (production) | 8.4 (`dunglas/frankenphp:1-php8.4-alpine`, CI 8.4) | 8.5 | 8.4: active support to 31 Dec 2026, security to 31 Dec 2028 | **Stay on 8.4** for now. Move to 8.5 together with Laravel 13 (local dev already runs 8.5.8 and all 470 tests pass on it) |
| `composer.json` PHP constraint | `^8.2` | — | 8.2 security ends 31 Dec 2026 | Raise to `^8.4` (matches production; stops an accidental install on an old PHP) |
| Laravel | 12.69.3 | 13.38.0 | **12 stopped receiving bug fixes on 13 Aug 2026; security fixes end 24 Feb 2027** | **Plan the 13 upgrade before February 2027** (see plan below). Highest-priority upgrade |
| Sanctum, permission, activitylog, sentry, openspout, resend | 4.3.2, 8.3.0, 5.0.0, 4.28.0, 5.12.0, 1.16.0 | 4.3.3, 8.3.2, 5.1.3, 4.29.0, 5.12.2, 1.17.0 | patch/minor, no advisories | Update in one `composer update` with the full test suite |
| Composer advisories | — | `composer audit`: none | ok | — |
| Node.js | local 24.11.1, CI 22, Railway web: not pinned | 24.22.1 (LTS "Krypton"), 26 (Current) | 22 and 24 both LTS | **Pin one version**: `"engines": {"node": "24.x"}` in `web/package.json`, CI `node-version: '24'`. Today CI builds on 22 and Railway picks its own default: the build is not reproducible |
| Next.js | 16.4.3 | 16.4.3 | latest; critical advisory of 16.4.0 fixed at checkpoint 7 | stay |
| React / React DOM | 19.3.0 | 19.3.1 | patch | update with the next web change |
| Vitest, Playwright | 5.0.4, 1.64.1 | 5.0.5 | patch | optional |
| npm advisories | — | `npm audit`: 0 | ok | — |
| PHPUnit | 11.5.55 | 13.4.3 | 11 works with Laravel 12 | move to the version Laravel 13 asks for, during that upgrade |
| MySQL | Railway MySQL service; server version not read in this check | — | **unverified** | Read it in Railway (`SELECT VERSION();`) and record it. Local lab: MySQL 26.10. The backup client in the image is MariaDB's `mysqldump`: the backup must be test-restored from production files (see readiness) |
| AI model | `OPENAI_MODEL` (default `gpt-4o-mini`; deploy example `gpt-6-luna`) | — | **unverified** which model production runs | Read the Railway variable; rerun `qa/s24`, `s10`, `s11` after any model change |
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
- Node.js releases: https://nodejs.org/en/about/previous-releases
- Package versions: `composer outdated --direct`, `npm outdated`, `npm view next version` (registry data, 24 Oct 2026)
- Advisories: `composer audit`, `npm audit` (both clean, 24 Oct 2026)
