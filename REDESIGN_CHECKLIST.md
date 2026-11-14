# NeoTalab redesign — checklist

`[x]` done and checked in a browser on desktop · `[~]` done, not yet checked everywhere · `[ ]` not started ·
`[–]` not in the product (see audit §6)

## Phase 1 — Audit
- [x] Routes, components, API, roles inventoried (`REDESIGN_AUDIT.md`)
- [x] Design problems listed
- [x] Tracking documents created

## Phase 2 — Design system
- [x] Tokens: colour roles, text hierarchy, status colours
- [x] Tokens: spacing, radii, shadows, control heights, type scale, motion, focus ring
- [x] Buttons: one family (primary, secondary, quiet, danger; small / medium / large)
- [x] Inputs, selects, textareas, search field: one height, radius, focus state
- [~] Checkboxes and switches
- [x] Tabs and segmented controls: one look
- [x] Filter pills: tinted instead of saturated, no glow
- [x] Status badges
- [x] Cards and stat tiles: no hover lift, one radius
- [x] Tables
- [~] Modals and confirmation dialogs
- [x] Side drawer
- [~] Toasts
- [~] Empty, loading (skeleton) and error states
- [x] Motion: remove decorative animation, keep feedback; reduced-motion respected
- [–] Date pickers, pagination, breadcrumbs, context menus

## Phase 3 — Shell and navigation
- [x] Sidebar (owner and shop)
- [~] Mobile top bar and slide-in menu
- [x] Page header
- [~] Command palette (owner search)

## Phase 4 — Platform owner
- [x] Merchants grid, filters, shop cards
- [x] Shop side panel (Info / Billing / WhatsApp)
- [~] Approve request dialog
- [x] Billing
- [~] Usage & costs
- [x] Inbox (To do / System / History)
- [x] Settings: Pricing
- [x] Settings: WhatsApp
- [x] Settings: Alerts
- [x] Settings: Security
- [–] Platform users, roles, analytics, activity log page

## Phase 5 — Shop dashboard
- [x] Get started
- [x] Menu list
- [x] Add / edit item, sizes, extras, offer
- [~] Menu import
- [x] Team
- [x] Customers
- [~] Bot memory
- [x] Settings: Shop & account
- [x] Settings: Orders
- [x] Settings: Delivery areas (map)
- [x] Settings: Opening hours
- [x] Settings: Payments
- [x] Settings: Report & chats
- [–] Orders, invoices, reports, conversations, AI performance

## Phase 6 — Staff and delivery
- [–] No web interface: staff and drivers work in WhatsApp. Nothing to redesign.

## Phase 7 — Sign-in and onboarding
- [~] Owner sign-in
- [~] Shop sign-in and first password
- [~] Forgot / reset password
- [x] Join form (3 steps)
- [–] Registration, email verification, subscription selection

## Phase 8 — Public site and remaining pages
- [x] Landing page
- [~] Legal pages and Contact
- [x] Page not found
- [~] Cookie banner

## Phase 9 — Languages and screen sizes
- [x] English, Arabic, French on every page (e2e: direction, no untranslated keys, no overflow)
- [x] Phone and tablet: public pages (e2e: no overflow, no errors, 3 languages)
- [~] Phone and tablet: dashboards (e2e passes; not yet looked at by eye)

## Phase 10 — Regression testing
- [x] Web unit tests
- [x] Production build
- [x] Backend tests (unchanged code, run as a safety net)
- [x] Playwright e2e on phone, tablet, desktop: 227 passed, 6 failed (2 outdated tests × 3 sizes), 3 skipped, 3 fixed but not re-run

## Phase 11 — Final consistency review
- [ ] Every route viewed after all changes
