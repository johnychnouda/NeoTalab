# NeoTalab redesign — audit

Audited on 2026-11-13 from the code on branch `redesign-ui` (cut from `main` at `27aed3e`).
Every number here was counted from the repository, not estimated.

## 1. What the product actually is

NeoTalab is WhatsApp commerce for local shops. Customers order and pay inside a WhatsApp chat, an AI
assistant takes the order, drivers and the shop work from WhatsApp too. The web app is for **setup and
oversight**, not daily operations (owner decision, 2026-11-13: no orders or sales pages on the web).

| Layer | Stack (as found) |
|---|---|
| API | Laravel 13, PHP 8.5, MySQL, Sanctum tokens, Spatie roles |
| Web | Next.js 16 (App Router), React 19, plain JSX (no TypeScript), one global stylesheet |
| Languages | English, Arabic (RTL), French — `web/lib/i18n*.js`, `web/lib/site-i18n.js` |
| Tests | Backend: PHPUnit (639). Web: Vitest (40 unit) + Playwright e2e (needs the QA lab logins) |

The brief names Laravel 12 / Next 15 / TypeScript / ESLint. The repository is on Laravel 13 / Next 16, has
no TypeScript and no ESLint config. Work follows the repository.

## 2. Frontend routes (25 route files, 20 addresses)

### Public (no sign-in)
| Address | File | Purpose |
|---|---|---|
| `/` | `app/page.jsx` | Landing: hero, live WhatsApp demo, features, pricing, FAQ, 3 steps |
| `/join` | `app/join/page.jsx` | 3-step shop application form |
| `/contact` | `app/contact/page.jsx` | Company, email, WhatsApp |
| `/privacy`, `/terms`, `/data-deletion` | `app/*/page.jsx` | Legal pages |
| `/reset-password` | `app/reset-password/page.jsx` | Choose a new password (from an emailed link) |
| any unknown address | `app/not-found.jsx` | Page not found |

### Platform owner (`/owner`, role: platform admin)
| Address | File | Purpose |
|---|---|---|
| `/owner` | `app/owner/page.jsx` | Sign-in; merchants grid, filters, requests, approve dialog, shop side panel |
| `/owner/billing` | `app/owner/billing/page.jsx` | Income tiles, shops table, record Whish payment, CSV export |
| `/owner/usage` | `app/owner/usage/page.jsx` | AI cost and message usage per month |
| `/owner/inbox` | `app/owner/inbox/page.jsx` | To do / System / History |
| `/owner/settings` | `app/owner/settings/page.jsx` | Pricing, WhatsApp, Alerts, Security |

### Shop dashboard (`/backoffice`, roles: merchant owner / manager)
| Address | File | Purpose |
|---|---|---|
| `/backoffice` | `app/backoffice/page.jsx` + `layout.jsx` | Sign-in, first-password, redirect to the shop |
| `/backoffice/[shop]` | `[shop]/page.jsx` | Get started: checklist, connect WhatsApp, plan box |
| `/backoffice/[shop]/menu` | `[shop]/menu/page.jsx` | Categories, items, sizes, extras, offers, import |
| `/backoffice/[shop]/drivers` | `[shop]/drivers/page.jsx` | Team (drivers) |
| `/backoffice/[shop]/customers` | `[shop]/customers/page.jsx` | All / Blocked / Low ratings, paused chats, anonymize |
| `/backoffice/[shop]/memories` | `[shop]/memories/page.jsx` | What the bot remembers |
| `/backoffice/[shop]/settings` | `[shop]/settings/page.jsx` | Shop & account, Orders, Delivery areas, Opening hours, Payments, Report & chats |
| `/backoffice/[shop]/analytics` | `[shop]/analytics/page.jsx` | Redirects to Get started (no page by design) |

## 3. Components (23 files)

Shell: `AppShell`, `DashSidebar`, `Brand`, `auth/AuthShell`, `public/SiteShell`, `public/SiteNavLinks`,
`public/LangSwitch`, `public/CookieConsent`.
Shared UI: `ui.jsx` (Toast, Modal, ConfirmModal, loaders), `owner/ui.jsx` (PageHeader, EmptyState,
ErrorBanner, InfoNote), `Segmented`, `FormSelect`, `PasswordReset`.
Feature: `owner/MerchantPanel` (side drawer, 767 lines), `owner/SettingsUI`, `owner/SystemAlerts`,
`owner/LaunchChecklist`, `owner/WhatsAppEmbeddedSignup`, `LocationPicker`, `ZoneEditor`,
`public/OrderDemo`, `public/PriceSwitch`, `public/Icon`.

## 4. Backend API (103 routes under `/api/v1`)

Groups: `auth` (8), `admin/*` owner portal (33), `merchant`/`settings/*` (16), catalog
(`categories`, `products`, `variants`, `modifier-groups`, `modifiers`, `menu` — 24), `customers` (6),
`drivers` (5), `memories` (3), `conversations` (3), `analytics` (1), public `plan` and
`onboarding/apply` (2), WhatsApp webhook (2). The redesign changes **none** of these.

## 5. Roles

| Role | Where it works |
|---|---|
| Platform owner | `/owner` web portal |
| Merchant owner / manager | `/backoffice` web dashboard |
| Driver | WhatsApp only (start, break, back, stop, picked up, outside, delivered, failed) |
| Shop on its phone | WhatsApp only (✅ ❌ ready out done delay retry refunded remember:) |
| Customer | WhatsApp only |

## 6. What the brief lists that the product does not have

Checked against the routes above. These are **not built and not planned** (WhatsApp-first product):
orders list and order details, invoices, reports and analytics pages for shops, staff dashboard,
delivery dashboard, conversations inbox, AI agent performance, platform users, roles and permissions
screens, activity log page (History lives inside Inbox), registration and email verification (shops
apply through `/join` and the owner approves), subscription self-service (paid by Whish, recorded by
the owner), date pickers, pagination, bulk actions, breadcrumbs, context menus.

## 7. Styling as found

- One file, `web/app/globals.css`, 3,180 lines, written in layers: a legacy owner/backoffice sheet, a
  `.dash-body` "polish" layer on top, a separate public-site palette (`.site --site-*`), and page layers.
- 20 CSS variables for colour and one radius; about everything else is a raw value. Raw greens such as
  `#25d36655`, `#33503f`, `#172820` are repeated hundreds of times.
- No spacing, type, radius, shadow or control-height scale.

## 8. Design and usability problems found

### System-level
1. No tokens beyond base colours, so every component invents its own radius (6–24px), control height
   (28–50px), shadow and hover.
2. Five button families (`btn-primary`, `btn-sm`, `btn-outline-green`, `btn-hero`, `auth-submit`,
   `paction-btn`) with different heights, radii, weights and text colours (`#000`, `#04220f`, `#04210f`).
3. Four tab patterns (`pill-btn`, `set-tab-btn`, `sset-tab`, `seg`).
4. Filter pills turn into saturated blue / indigo / orange / red blocks with coloured glows when active.
5. Decorative motion: cards lift on hover, buttons scale on press, nav items slide, table rows fade in
   one by one, every page section fades up.
6. Glows and gradients on cards, sidebar, buttons and the active nav marker.
7. Focus ring is a 44%-opacity green outline: weak against dark surfaces.
8. Inputs differ per page: 8–10px radius, 3 backgrounds, with and without a focus ring.
9. Labels were all-caps in the legacy layer and are overridden to sentence case only inside `.dash-body`.

### Page-level (from the review on 2026-11-13)
- Owner: inbox badge contradicted the page (fixed), duplicate "Not connected" on cards (fixed), name in
  corner changed (fixed), money written two ways (`$300` in portal, `300$` on the public site — the
  public one is deliberate, to match the chats).
- Shop: free plan showed "Payment due" (fixed), mixed WhatsApp status messages (fixed), tick box layout
  (fixed), Get started narrower than other pages (widened), empty states without an action on Menu.
- Arabic: no Arabic font (fixed), side menu stays on the left **by design** (comment in the stylesheet).
- Public: default white 404 (fixed), small demo buttons on phones (fixed), final CTA mismatch (fixed).

### Not yet verified
- Phone and tablet layout of the two dashboards (needs a signed-in session at phone width).
- Shop pages with real data (Demo Shop is empty); owner Settings tabs other than Pricing; shop Settings
  tabs other than Shop & account.
