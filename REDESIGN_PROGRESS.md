# NeoTalab redesign — progress

Last updated: 2026-11-14 (after the e2e run) · Branch: `redesign-ui` (from `main` at `27aed3e`) · **Not committed yet.**

Read `REDESIGN_AUDIT.md` first, then `REDESIGN_CHECKLIST.md`, then continue from "Exact next steps".

## Honest status

The **design system and the shared look are done and checked on desktop** in the three languages.
The redesign is **not finished**: phone and tablet layouts of the two dashboards are unverified, pages
could only be seen with an empty shop, and several pages got the shared system but no page-level rework.

## Done

### Design system (`web/app/globals.css`)
- Tokens added to `:root`: surface and border steps, text levels, brand, four status colours each with
  a tint and a line, corner scale, 4px spacing scale, three control heights, type scale, two shadows,
  focus ring, motion duration. Existing variables kept, so nothing that used them changed meaning.
- A "DESIGN SYSTEM" section at the end of the stylesheet is now the single place for shared controls:
  - **Buttons**: primary, danger, secondary (`btn-sm`), tinted (`btn-outline-green`), sign-in submit —
    one height per size, one corner, one text colour on green, no glow, no press-scale.
  - **Fields**: one height (40px, 44px on phones with 16px text so iOS does not zoom), one corner,
    hover line, focus line plus ring, one placeholder colour. Covers `.field`, `.sset-field`, search,
    filter select, drawer credential fields, opening-hours time fields.
  - **Filter pills**: tinted in their own colour when chosen instead of saturated blocks with glows.
  - **Status badges**, including a neutral default for a badge with no tone.
  - **Cards, stat tiles, tables**: one corner, a line, no hover lift, no decorative gradients.
  - **Tabs**: owner Settings and shop Settings share one quiet bar.
  - **Sidebar**: flat surface, 40px rows (44px on phones), no slide or glow.
  - **Dialogs and drawer**: the only elements with a shadow; equal-height footer buttons; even field
    spacing in compact dialogs.
  - **Toasts**, **empty states**, **focus ring** (solid green, 2px), `color-scheme: dark` so native
    tick boxes, time fields and scrollbars match.
  - **Motion**: page, row and card entrance animations removed; hover lifts and press-scales removed.
    `prefers-reduced-motion` was already respected and still is.
  - **Labels**: sentence case everywhere in the apps (24 all-caps label styles normalised).

### Page work
- **Owner › shop side panel**: close button moved beside the title (was on an empty strip); labels in
  sentence case; action buttons one height.
- **Shop › Customers**: plain-language subtitle; tabs and search on one toolbar row; search field with
  an icon and an accessible name; empty state explains when customers appear; "paused" badge toned.
- **Shop › Menu**: empty state has a title, an explanation and an "Add category" button; errors use the
  standard error banner; header actions aligned to one height.
- **Shop › Settings**: tab bar keeps the same width on every tab (it used to shrink on Opening hours).
- **Public**: section menu highlight is a tint (solid green reserved for the call to action); waiting
  phones in the demo no longer fade to near-black; pricing card lost its green halo.
- Earlier the same week (already on `main`): Arabic font, 404 page, inbox subtitle, plan box, WhatsApp
  status wording, tick box layout, join tile, final CTA.

### Verified in a browser (desktop, 1440px, signed in on localhost)
| Area | Pages seen after the changes | Languages |
|---|---|---|
| Owner | Merchants, shop panel (Info, Billing, WhatsApp), Billing, Inbox (To do, System, History), Settings (Pricing, WhatsApp, Alerts, Security) | EN; AR on Merchants + panel |
| Shop | Get started, Menu, Add item dialog, Team, Add member dialog, Customers, Settings (all six tabs) | EN; AR on Customers; FR on Settings › Payments |
| Public | Home, Join, Reset password | EN |

## Test results (2026-11-14)
| Check | Result |
|---|---|
| Web unit tests (`npm test`) | 40 passed |
| Web production build (`npm run build`) | Succeeds |
| Backend tests (`php artisan test`) | 639 passed (backend untouched by the redesign) |
| Playwright e2e, phone + tablet + desktop (Chromium), against the QA lab | 239 tests: **227 passed, 6 failed, 3 skipped, 3 not re-run after their fix** (see below) |
| TypeScript, ESLint | Not applicable: the project has neither |

**How the e2e run was done.** Playwright's Chromium was installed (approved 2026-11-14). The QA lab
(`qa/setup_lab.sh`: throwaway SQLite database, fake AI, fake Meta) served the API on 8011 and a
production build of the web app on 3011. The first parallel run hit the lab API's rate limit (HTTP 429)
and failed 49 tests; re-running those one at a time (`--last-failed --workers=1`) left 5 real failures
per screen size. Three were fixed:
- Menu page: the buttons inside the closed "More" menu were announced as unnamed controls. The closed
  menu is now `display: none`.
- Two tests looked for old wording ("Reports & chats", "Whish Pay"); updated to the current
  "Report & chats" and "Whish transfer".
  The "Whish transfer" one was re-run and passes. The "Report & chats" tab test (3 sizes) was updated
  but **not re-run**: renaming the test took it out of the `--last-failed` set and the lab was stopped.

**Still failing (2 tests × 3 screen sizes), not caused by the redesign** — both look for text the
pages no longer show, in files the redesign did not touch:
- `dashboards.spec.js` "the Whish badge…": expects the sentence "How it works: after you accept an order…";
  the Payments tab now shows three numbered steps instead.
- `whatsapp-badge.spec.js` "once Meta says the number is connected…": expects "Connected: +961 70 207 078".
Decide whether the pages or the tests are right, then update one of them.

**What the run proves:** on phone (Pixel 5), tablet (820px) and desktop (1280px), in English, Arabic
and French, every public page, every owner page and tab, and every shop page and tab loads with no
sideways scroll, no crash, no server error and no untranslated key; every control has an accessible name.
Firefox and Safari projects were not run.

No known regression from the redesign. No dependency added or removed.

## Not done / not verified
1. **Phone and tablet were tested by machine, not by eye.** The e2e run shows nothing overflows or
   breaks; nobody has looked at how the dashboards actually look on a phone.
2. **Pages with real data.** Demo Shop is empty: menu list with items, customer cards, team cards,
   memory list, low-ratings list and the owner Usage table were not seen populated.
3. **Not opened after the changes:** owner Usage & costs, owner approve-request dialog, record-payment
   dialog, command palette (⌘K), shop Bot memory, menu import dialog, shop sign-in and first-password
   screens, legal pages, Contact, cookie banner, confirmation dialogs.
4. **French and Arabic** were spot-checked, not walked page by page.
5. **Stylesheet debt.** The design system sits on top of the older layers. The older rules it overrides
   are still in the file (3,410 lines). Deleting them safely needs the e2e suite running.
6. **Accessibility.** Focus ring, labels on the new search field and contrast of text tokens were
   handled; no full keyboard or screen-reader pass was done.

## Known issues found on the way (not caused by the redesign)
- **Google Maps is not loading**: console shows `BillingNotEnabledMapError`. The delivery-area map and
  shop-location map show "This page can't load Google Maps correctly". Billing must be enabled on the
  Google Cloud project that owns the Maps key.
- **Live site down**: `neotalab.com` returns Railway's "Not Found" (seen 2026-11-13).
- **Owner › shop panel › Billing shows "Next payment —"** for a shop whose Billing table row shows
  21 Jul 2027. Likely a field mismatch in `MerchantPanel.jsx`; not investigated.
- **Owner › Inbox › System** (local environment) lists setup gaps: scheduled tasks not running, alert
  emails, alert recipients and nightly backups not configured. Check the same list on production.
- **Owner › Settings › WhatsApp** (local): platform number "Not working" (Graph API object not found).
- **Reset password** with a bad link says "Ask for a new one" but offers no button to do so.
- **History** entries are stored in the language used at the time, so one list mixes three languages.

## Decisions to keep
- No orders, sales or analytics pages on the web: daily work happens in WhatsApp (owner, 2026-11-13).
- The side menu stays on the left in Arabic (existing, documented in the stylesheet).
- Prices on the public site are written `300$` to match the chats; the owner portal uses `$300`.
- Dark green identity kept; green is reserved for the primary action and positive status.

## Exact next steps
1. Look at the dashboards on a real phone width (the lab shop `qa-manakish-corner` has a menu, a zone
   and a driver, so pages are populated). To rerun the suite: `bash qa/setup_lab.sh`, start the lab API
   on 8011 and the mock graph, build the web app with `NEXT_PUBLIC_API_URL=http://127.0.0.1:8011/api/v1`
   and `next start -p 3011`, set the `E2E_*` variables from `qa/.lab`, then
   `npx playwright test --workers=1`. The lab shop account must set its first password before
   `auth.setup.js` can sign in.
2. Seed Demo Shop with a menu, a driver, customers and a memory, then review the populated pages.
3. Open and review the items in "Not opened after the changes".
4. Walk every page in Arabic and French.
5. Remove the overridden legacy rules from `globals.css`, section by section, with e2e green.
6. Keyboard and screen-reader pass on dialogs, drawer, tabs and the menu editor.
