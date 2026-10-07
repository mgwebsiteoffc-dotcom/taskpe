# TaskPe — Team tasks with WhatsApp alerts, inside Shopify Admin

A Laravel 12 + MySQL **public Shopify app** for Indian D2C teams:

- 📋 **Polaris-styled Kanban** (To Do → In Progress → Done + custom columns) embedded in Shopify Admin
- 🔗 **Link any object** to a task — Order, Draft Order, Product, Customer, Blog post — one click deep-links into that Shopify Admin page
- 🟢 **WhatsApp to staff via YOUR Whatify account** (BYO-BSP): assignment pings, nudges, and the owner's morning **Bird's-Eye-View digest**. ~₹0.12/message on your own Whatify wallet
- 🎛 **WhatsApp is merchant-controlled**: a master **Enable/Disable** switch in the Admin Settings panel — OFF by default. Turned off = board/team/linking keep working, nothing ever hits WhatsApp.
- ⚡ **Work happens where the order is** — "Create task" in *More actions* on Order / Draft Order / Product / Customer pages (modal, resource pre-linked), **bulk "Create TaskPe tasks"** on the Orders list (tick 30 COD orders → 30 pre-filled checklist tasks, safe to re-run), and an **order-page block** that shows that order's open tasks with tickable checklist steps. Code in `extensions/`, built per the current preact + `s-admin-action` / `s-admin-block` API.
- **Keyboard-first board** — `n` new task, `t` template pack, `c` complete the open task, `1`–`4` switch sections, `?` lists them, `Esc` closes. Inert while you type, so a stray letter never files junk.
- 👥 **Team roster with WhatsApp OTP verification** (doubles as Meta-compliant opt-in) — staff see own tasks, owners see everything
- 💳 **Billing handled by Shopify, in the store's currency** — plans, prices and trials are created in the Partner Dashboard, so the price a merchant sees and pays is Shopify's, in that store's billing currency, on the Shopify invoice. The app's Plan tab only mirrors what Shopify reports it charges; it never quotes or creates a price of its own. (`SHOPIFY_BILLING_MODE=api` keeps the legacy in-code price table for apps that still bill through `appSubscriptionCreate`.)
- 📦 **COD/NDR template pack** — one-click India-D2C task checklists (COD confirmation, NDR rescue, high-risk order check, prepaid conversion, address fix, delayed-shipment save, weekly COD remittance reconciliation, return pickup). Tasks are created pre-filled with a **tickable checklist** and an order link; board cards show ☑ 2/6 progress
- 🤖 **Optional COD auto-task** — switch on in Settings and every new Cash-on-Delivery order instantly gets the confirmation checklist task from the `orders/create` webhook (lazily registered on enable, so old installs need no reinstall). OFF by default, idempotent per order, and respects the Free plan's 50-task ceiling
- 🌐 **Staff web portal (`/staff`)** — Shopify Basic gives merchants only ONE staff seat, so packers/VAs can't open Shopify admin at all. Fix: per-member **personal invite links** (minted/revoked from the Team tab) open the SAME board on any phone browser — full template pack, checklists, due dates; no delete/settings/billing. No Shopify account needed, works on Free plan, add-to-homescreen friendly. Optional self-serve **WhatsApp OTP sign-in** when WhatsApp is on. Tenant-scoped identically to the admin API (same ShopContext, same plan limits)
- 🎬 **First-run onboarding** — after install, admins get a clean 5-step intro tour (hand-drawn inline SVG art, **zero emojis**): what the board does, linking tasks to Shopify objects, the COD/NDR India workflows, team + optional WhatsApp, and a quick-start recap. Skipped or finished once per shop (shared across staff); replayable anytime from Settings → Setup guide
- 🚑 **Optional NDR watcher** — paste your per-shop secret intake URL into Shiprocket / Delhivery / XpressBees webhook settings; every NDR push becomes an **urgent 12-hour rescue task**, auto-linked to the order (found via Shopify search) with the courier's NDR reason + AWB on top. No open NDR duplicates, AWB-only pushes still create tasks
- 🔁 **Weekly COD remittance chore** — one switch and the "COD remittance check" task recreates itself every week until completed (runs on the existing single cron; never piles up open copies)

**Navigation is Shopify's, not ours:** Board · Team · Settings · Plan are menu items in the admin's left sidebar (App Bridge v4 reads an `<s-app-nav>` of `<s-link>`s that the SPA mounts — the retired `<ui-nav-menu>` is ignored, so the tag name is load-bearing; each entry is a path Laravel answers with the same shell). The app paints no header, no tab strip and no brand bar — it starts at its content. Keyboard `1`–`4` / clicking a menu item switch in place (no iframe reload), and the sidebar highlight follows the path.

Built to the **2026 public-app requirements** — see the compliance map below.

---

## Stack

| Layer | Choice | Why |
|---|---|---|
| Backend | Laravel 12, PHP ≥ 8.2 | Runs on cheap shared hosting |
| DB | MySQL 8 (SQLite for tests) | cPanel standard |
| Auth | OAuth (offline token) + App Bridge v4 session tokens (JWT, HS256) | 2026 embedded-app rule: no cookie auth |
| WhatsApp | Whatify **External API** (`X-API-Key`, bring-your-own-key) | Merchants reuse the BSP they already pay for |
| Jobs | Database queue + `schedule:run` cron | No Redis/supervisor needed on shared hosting |
| Frontend | App Bridge v4 (Shopify CDN) + hand-written Polaris CSS + vanilla JS | Zero build step — FTP upload just works |
| Iconography | Inline SVG from the `ICONS` map in `public/js/app.js` (`icon(name)`) | No emoji: OS-dependent glyphs, untintable, wrong at 13px — and `boot.smoke.mjs` fails the build if one appears |
| Navigation | Shopify's own app menu: App Bridge v4 `<s-app-nav>` + `<s-link>`s, one path per section (`/team`, `/settings`, `/plan`) | The admin sidebar (and the mobile title-bar menu) is the menu — nothing inside the app duplicates it, and there is no app header |
| Admin surface | 6 UI extensions: 4 actions + 1 bulk selection action + 1 order-page block, sharing `extensions/shared/api.js` | Shopify has no per-row list button; these are the three real affordances, and one shared file keeps APP_URL/auth from drifting |
| Dead grant | `Shop::markTokenRejected()` + `php artisan taskpe:doctor` | When Shopify refuses a stored access token the shop is flagged, so the board asks to reconnect, webhook registration stops hammering a 401, and digests skip that store — one reconnect (or `APP_KEY` restored) clears all of it |
| Expiring Admin tokens | `App\Services\TokenVault` + `php artisan taskpe:tokens` | Shopify refuses a **non-expiring** offline token for public apps, so the app asks for the expiring pair (`expiring=1`), stores the access token, the refresh token and both absolute expiry dates, renews lazily on the next API call, converts stores that installed before the change (no reinstall), and is checked by a daily pass so a quiet store never runs out. DEPLOYMENT.md § 19 |
| Bulk creation | `POST /api/tasks/bulk` via `App\Services\TaskTemplates` | One materialisation path for board, block, bulk and the COD webhook: same title format, same checklist, same plan ceiling |

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test        # PHP suite: session-token boundary, tenant isolation, plan gates, OTP, billing, webhooks
php artisan serve                  # or: composer dev
node tests/js/boot.smoke.mjs       # SPA boot screens — no framework, plain node
                                   # or: composer test:js   /   npm run test:js
node tests/js/extensions.static.mjs # Admin extensions: build-config sanity (npm run test:extensions)
node tests/js/extensions.bundle.mjs # …and the real esbuild bundle of all six (npm run test:bundle)
```

**No `npm install`, no `npm run dev`, no `npm run build` — this app has no bundler.**
`public/js/app.js` and `public/css/app.css` *are* the sources and are served as written,
which is what makes "upload the files" a complete deploy. `npm run build` prints that
sentence and exits non-zero on purpose, so the wrong command can't fail as a cryptic
Rollup/Vite error ("Could not resolve entry module index.html" — that was Laravel's
default `composer dev` script still calling `npm run dev`; it is gone now). The one place
Node appears is **building the Shopify Admin extensions**, and even there nothing
compiles the app: `npm install` once at the repo root (it is the npm *workspace root*
for `extensions/taskpe-*`, so `extensions/shared/*.jsx` can resolve `preact`), then
`shopify app build` bundles each extension. `npm run test:extensions` static-checks
those folders (tsconfig, entry imports, targets, locale keys) without needing the CLI.
See `extensions/README.md`.

To test against real Shopify, point a tunnel (cloudflared/ngrok) at the app and install it on a dev store via the Partner Dashboard.

## "Something went wrong loading the board" ?

That sentence is gone by design: every failure now names itself. The board needs a
Shopify **session token**, which only exists while the app is opened *inside*
`admin.shopify.com` (or, for teammates, inside the `/staff` portal with a portal
cookie). Opening the app URL as a plain link therefore shows a **connect your
store** screen — a login step, not a crash.

| What you see | What it means |
|---|---|
| "Open TaskPe from your Shopify admin" + a store-domain box | no session (link opened outside the admin) — Apps → TaskPe, or type the domain to install |
| …and "stripping the Authorization header" | shared-hosting SAPI ate `Authorization`; see **DEPLOYMENT.md § 3** |
| "The board could not be loaded" + an SQL / HTTP message | real server-side failure — the message is the one from `/api/board`, plus `storage/logs/laravel.log` |
| "TaskPe is not configured on this server yet" | `APP_KEY` / `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` / `APP_URL` / unmigrated DB — listed on screen |
| "Shopify has not approved this app for the store's order data yet" (in order search) | the protected customer data review is still pending — `read_all_orders` is granted to the *app*, not the store; see **DEPLOYMENT.md § 10** |

Full deploy + failure-mode guide: **DEPLOYMENT.md**.

## Partner Dashboard configuration (do these once)

**App setup (Overview → Configuration):**

| Field | Value |
|---|---|
| App URL | `https://app.yourdomain.com/` |
| Allowed redirection URL(s) | `https://app.yourdomain.com/auth/shopify/callback` and `https://app.yourdomain.com/billing/callback` |
| Embedded in Shopify admin | ✅ Enabled |
| API version | Same as `SHOPIFY_API_VERSION` (`2026-07`) — for both Admin API and Webhooks |

**Client credentials** → copy into `.env` (`SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`).

**Webhooks:** the app registers `APP_UNINSTALLED`, `CUSTOMERS_DATA_REQUEST`, `CUSTOMERS_REDACT`, `SHOP_REDACT` itself on install (GraphQL). Also add the mandatory GDPR topics in the Dashboard UI pointing to `https://app.yourdomain.com/webhooks/shopify` so review sees them configured.

**Protected customer data (Access → Data protection):** this app reads orders/draft orders and customer *display names* only to build link labels. **No PCD fields** (email/address/phone) are accessed or stored. Declare exactly that; listing review routinely passes with this justification.

**Billing:** who owns the price is a switch, `SHOPIFY_BILLING_MODE`. **`shopify` (default)** — the plans are created in the Partner Dashboard (Shopify App Pricing): Shopify shows the price in each store's billing currency and bills it on the Shopify invoice, so the app neither calls `appSubscriptionCreate` nor displays `config/shopify.php → plans.*.prices`; the Plan tab reads the amount, currency, interval and renewal date off the store's own subscription instead (`BillingService::readBilling()`, cached in `shops.settings.billing`). **`api`** — the `prices` map is the price table and the app creates the subscription, pricing a store in its billing currency when the map has that key and falling back to USD otherwise; while unpublished/dev, `SHOPIFY_BILLING_TEST=true` keeps those charges in test mode. See `DEPLOYMENT.md` § 17.

## 2026 compliance map (how each requirement is met)

| Requirement | Where |
|---|---|
| Session-token auth, no cookies | `app/Http/Middleware/VerifyShopifySessionToken.php` — HS256 JWT, alg allow-list, aud/iss/dest/exp/nbf checks (`app/Support/JwtToken.php`) |
| OAuth + HMAC + state | `AuthController` + `ShopifyHmac` (raw-query HMAC, signed cookieless state) |
| Mandatory GDPR webhooks | `WebhookController` (HMAC on raw body, idempotent) → `ProcessShopifyWebhook` job; `shop/redact` hard-deletes the tenant |
| Minimal scopes + PCD | `.env SHOPIFY_SCOPES`; search queries select only non-PII fields (`ResourceSearchController`) |
| Billing owned by Shopify | `config/shopify.php → billing.mode`; `BillingService::readBilling()` mirrors the subscription for the Plan tab, and status is re-synced server-side before limits change |
| Embedded, App Bridge from Shopify CDN | `resources/views/app.blade.php` (`meta shopify-api-key` + official CDN script only) |
| API version pinned & quarterly upgrade | `config/shopify.php` (`SHOPIFY_API_VERSION`) |
| Rate-limit respect | `ShopifyClient` retries 429/THROTTLED with backoff |
| Data minimisation | tokens/Whatify key stored **encrypted**; logs pruned after 90 days (`taskpe:prune`) |
| Privacy policy URL | `/privacy` (point the listing to it) |
| Meta/WhatsApp policy | OTP = opt-in; only verified staff are pinged; approved templates preferred, 24h-window text as fallback |

## Whatify templates to create (exact body copy also shown in-app)

| Name | Category | Body |
|---|---|---|
| `taskpe_otp` | utility | `Your TaskPe verification code is {{1}}. It expires in 10 minutes.` |
| `taskpe_task_assigned` | utility | `Hi {{1}}, new task assigned: {{2}}\nDue: {{3}}\nLinked: {{4}}\nOpen: {{5}}` |
| `taskpe_task_reminder` | utility | `Reminder, {{1}} — task needs attention: {{2}}\nDue: {{3}}\nLinked: {{4}}\nOpen: {{5}}` |
| `taskpe_daily_digest` | utility | `Team task summary for {{1}}:\n{{2}}` |

If a template isn't mapped in Settings, the app falls back to plain text (delivers inside Meta's 24h service window). Every send is logged in **Settings → WhatsApp delivery log**.

## Admin "Create task" extensions (`extensions/`)

Six UI extensions, one backend. Four add **More actions → Create task** on Order / Draft Order / Product / Customer pages; **`taskpe-task-order-bulk`** adds **Create TaskPe tasks** to the Orders-list selection menu (bulk, `POST /api/tasks/bulk`); **`taskpe-order-block`** pins an inline card on the order page (open tasks + checklist ticks + one-tap templates). All of them reuse the same session-token-authenticated `/api/*` backend — no separate auth surface, and shared code in `extensions/shared/` (`api.js`, `CreateTaskAction.jsx`, `BulkCreateTasks.jsx`, `OrderTaskBlock.jsx`).

```bash
# Local machine only (NOT the server — extensions run on Shopify's CDN):
npm install -g @shopify/cli@latest
cp shopify.app.toml.example shopify.app.toml     # fill client_id + application_url
# set APP_URL once, in extensions/shared/api.js (all six extensions import it)
for d in extensions/taskpe-*/; do (cd "$d" && npm install --omit=dev); done
shopify app deploy                                # then release in Partner Dashboard
```

Full guide (incl. dev-store preview with `shopify app dev`): **`extensions/README.md`**. Deploying also requires CORS on the API — already configured in `config/cors.php` (`/api/*` only; bearer-token auth, no cookies).

## Directory tour

```
app/Http/Middleware      session-token + webhook-HMAC guards
app/Services             ShopifyClient (GraphQL, retry), WhatifyClient, TaskNotifier,
                         DigestService, BillingService, WebhookRegistrar
app/Http/Controllers     OAuth / webhooks / billing + /api/* tenant-scoped JSON
app/Jobs                 ProcessShopifyWebhook, SendWhatsAppJob
config/cors.php          API CORS for the admin extensions (/api/* only)
public/js/app.js         the whole SPA (dashboard + charts, one task database in Board/Table/Calendar views, drawer, resource picker, team, settings, plan, shortcuts, admin app-menu mount)
extensions/              6 Admin UI extensions (Create task / bulk / order-page block) + shared/
resources/views          app shell (App Bridge) + privacy policy
config/task_templates.php  The COD/NDR one-click checklist pack (edit copy/add templates here)
LISTING.md               App Store submission pack: listing copy, screenshot shot-list, reviewer instructions
assets/app-icon.png      App icon for the listing
tour-preview.html        Static preview of the 5-slide onboarding (open in any browser)
boot-preview.html        Static preview of the four board-load states (see DEPLOYMENT.md § 5)
ui-preview.html          The REAL SPA on fake data — open it to judge the board/table/calendar design without deploying
tests                    PHP suite (`php artisan test`) + tests/js/boot.smoke.mjs (SPA boot screens)
```

Deploy to shared hosting (and triage a broken board)? See **DEPLOYMENT.md**.
