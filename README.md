# TaskPe — Team tasks with WhatsApp alerts, inside Shopify Admin

A Laravel 12 + MySQL **public Shopify app** for Indian D2C teams:

- 📋 **Polaris-styled Kanban** (To Do → In Progress → Done + custom columns) embedded in Shopify Admin
- 🔗 **Link any object** to a task — Order, Draft Order, Product, Customer, Blog post — one click deep-links into that Shopify Admin page
- 🟢 **WhatsApp to staff via YOUR Whatify account** (BYO-BSP): assignment pings, nudges, and the owner's morning **Bird's-Eye-View digest**. ~₹0.12/message on your own Whatify wallet
- 🎛 **WhatsApp is merchant-controlled**: a master **Enable/Disable** switch in the Admin Settings panel — OFF by default. Turned off = board/team/linking keep working, nothing ever hits WhatsApp.
- ⚡ **"Create task" Admin Action Extensions** — real buttons on Shopify's Order, Draft Order, Product and Customer pages (*More actions → Create task*) that open a modal with the resource pre-linked. Code in `extensions/`, built per the current preact + `s-admin-action` API.
- 👥 **Team roster with WhatsApp OTP verification** (doubles as Meta-compliant opt-in) — staff see own tasks, owners see everything
- 💳 **In-Shopify billing in the merchant's own currency** — Indian stores approve **₹499 / ₹999 per month in INR** (local INR pricing via the Billing API's merchant-billing-currency support, no FX fees); stores in other billing currencies see **$5.99 / $11.99 USD**. Nothing is ever charged outside Shopify
- 📦 **COD/NDR template pack** — one-click India-D2C task checklists (COD confirmation, NDR rescue, high-risk order check, prepaid conversion, address fix, delayed-shipment save, weekly COD remittance reconciliation, return pickup). Tasks are created pre-filled with a **tickable checklist** and an order link; board cards show ☑ 2/6 progress
- 🤖 **Optional COD auto-task** — switch on in Settings and every new Cash-on-Delivery order instantly gets the confirmation checklist task from the `orders/create` webhook (lazily registered on enable, so old installs need no reinstall). OFF by default, idempotent per order, and respects the Free plan's 50-task ceiling
- 🌐 **Staff web portal (`/staff`)** — Shopify Basic gives merchants only ONE staff seat, so packers/VAs can't open Shopify admin at all. Fix: per-member **personal invite links** (minted/revoked from the Team tab) open the SAME board on any phone browser — full template pack, checklists, due dates; no delete/settings/billing. No Shopify account needed, works on Free plan, add-to-homescreen friendly. Optional self-serve **WhatsApp OTP sign-in** when WhatsApp is on. Tenant-scoped identically to the admin API (same ShopContext, same plan limits)
- 🎬 **First-run onboarding** — after install, admins get a clean 5-step intro tour (hand-drawn inline SVG art, **zero emojis**): what the board does, linking tasks to Shopify objects, the COD/NDR India workflows, team + optional WhatsApp, and a quick-start recap. Skipped or finished once per shop (shared across staff); replayable anytime from Settings → Setup guide
- 🚑 **Optional NDR watcher** — paste your per-shop secret intake URL into Shiprocket / Delhivery / XpressBees webhook settings; every NDR push becomes an **urgent 12-hour rescue task**, auto-linked to the order (found via Shopify search) with the courier's NDR reason + AWB on top. No open NDR duplicates, AWB-only pushes still create tasks
- 🔁 **Weekly COD remittance chore** — one switch and the "COD remittance check" task recreates itself every week until completed (runs on the existing single cron; never piles up open copies)

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

## Local development

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan test        # 83 tests: HMAC, JWT, tenant isolation, plan gates, OTP, Whatify client, billing currency, COD/NDR automation, onboarding, staff portal, demo seeder
php artisan serve
```

To test against real Shopify, point a tunnel (cloudflared/ngrok) at the app and install it on a dev store via the Partner Dashboard.

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

**Billing:** charges run through the Shopify Billing API only. Plans are priced **per billing currency** (`config/shopify.php → plans.*.prices`): INR stores are charged ₹499/₹999 directly; anything without a local entry falls back to USD, with an automatic USD retry if Shopify disagrees about a shop's billing currency (shop currency ≠ billing currency edge case). Extending to more markets is one `prices` key away (e.g. `'GBP' => 4.99`). While unpublished/dev, `SHOPIFY_BILLING_TEST=true` keeps charges in test mode.

## 2026 compliance map (how each requirement is met)

| Requirement | Where |
|---|---|
| Session-token auth, no cookies | `app/Http/Middleware/VerifyShopifySessionToken.php` — HS256 JWT, alg allow-list, aud/iss/dest/exp/nbf checks (`app/Support/JwtToken.php`) |
| OAuth + HMAC + state | `AuthController` + `ShopifyHmac` (raw-query HMAC, signed cookieless state) |
| Mandatory GDPR webhooks | `WebhookController` (HMAC on raw body, idempotent) → `ProcessShopifyWebhook` job; `shop/redact` hard-deletes the tenant |
| Minimal scopes + PCD | `.env SHOPIFY_SCOPES`; search queries select only non-PII fields (`ResourceSearchController`) |
| Billing API only | `BillingService` (`appSubscriptionCreate`, server-side status sync before plan upgrade) |
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

Four UI extensions add **More actions → Create task** on Order / Draft Order / Product / Customer admin pages. They reuse the same session-token-authenticated `/api/*` backend — no separate auth surface.

```bash
# Local machine only (NOT the server — extensions run on Shopify's CDN):
npm install -g @shopify/cli@latest
cp shopify.app.toml.example shopify.app.toml     # fill client_id + application_url
# set APP_URL inside extensions/shared/CreateTaskAction.jsx
for d in extensions/taskpe-task-*/; do (cd "$d" && npm install --omit=dev); done
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
public/js/app.js         the whole SPA (board, drawer, resource picker, team, settings, plan)
extensions/              4 Admin Action UI extensions ("Create task") + shared component
resources/views          app shell (App Bridge) + privacy policy
config/task_templates.php  The COD/NDR one-click checklist pack (edit copy/add templates here)
LISTING.md               App Store submission pack: listing copy, screenshot shot-list, reviewer instructions
assets/app-icon.png      App icon for the listing
tour-preview.html        Static preview of the 5-slide onboarding (open in any browser)
tests                    83 tests — run `php artisan test`
```

Deploy to shared hosting? See **DEPLOYMENT.md**.
