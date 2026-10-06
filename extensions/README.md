# TaskPe — Shopify Admin extensions

Six extensions, all pointed at one backend. Everything a merchant touches while
working an order, without leaving the order:

| Extension folder | Where it shows up | Target |
|---|---|---|
| `taskpe-task-order` | Order page → *More actions* | `admin.order-details.action.render` |
| `taskpe-task-order-bulk` | Orders list → tick rows → bulk menu | `admin.order-index.selection-action.render` |
| `taskpe-order-block` | Inline card on the order page (merchant pins it once) | `admin.order-details.block.render` |
| `taskpe-task-draft-order` | Draft order page → *More actions* | `admin.draft-order-details.action.render` |
| `taskpe-task-product` | Product page → *More actions* | `admin.product-details.action.render` |
| `taskpe-task-customer` | Customer page → *More actions* | `admin.customer-details.action.render` |

| Action | What it does |
|---|---|
| **Create task** (modal) | One task, this order/product/customer pre-linked, with assignee, priority and due date. |
| **Create TaskPe tasks** (bulk) | One template × every selected order. Tick 30 COD orders → 30 tasks with the COD checklist, then the modal tells you how many were skipped and why. |
| **Order-page card** (block) | This order's open tasks, checklist steps you can tick right there, *Mark done*, one-tap template buttons, and a link that opens the task on the board. |

(Blog posts have no admin-action target in Shopify — link them from inside the
app instead: search, or paste the article URL.)

## What Shopify does *not* allow (and what we do instead)

- **No button inside an individual row of the Orders list.** There is no target
  for it — the only two list-level affordances are the bulk selection menu and
  the page's *More actions*. So "create a task for this order" is: tick → bulk
  action (fastest for batches), or open → More actions (fastest for one).
- **Blocks are opt-in per merchant.** Shopify's own rule: *"merchants must add
  and pin blocks to their pages before they can use them."* The order-page card
  therefore never replaces the action/bulk targets — it only adds the tick-in-place
  workflow. Settings → **In your Shopify admin** states this in the app, too.
- **Blocks are read-mostly.** Their height is capped (~300px, then "Show more")
  and the block component set has no text inputs, so the card deliberately has
  no free-text field. Anything that needs typing belongs in the action modal.

## Files

```
extensions/shared/api.js                 APP_URL + session token + api() + GID→type
extensions/shared/CreateTaskAction.jsx   the modal (4 action extensions)
extensions/shared/BulkCreateTasks.jsx    the bulk picker (order-index selection)
extensions/shared/OrderTaskBlock.jsx     the order-page card (block)
extensions/<name>/shopify.extension.toml target + capabilities (+ uid injected by CLI)
extensions/<name>/src/*.jsx              two-line wrapper: imports the shared file
extensions/<name>/locales/en.default.json
```

Edit `shared/api.js` **once** — `APP_URL` there is used by every extension, so
the modal, the bulk action and the card can never disagree about where the
backend is. Each extension folder stays a two-line wrapper on purpose: Shopify
resolves the module path per extension, so the shared logic lives one level up.

## How it authenticates

The extension (running inside Shopify's sandbox) requests a **session-token JWT**
(`shopify.auth.idToken`) and calls the same `/api/*` backend as the embedded app
(`app/Http/Middleware/VerifyShopifySessionToken.php`) — same tenant scoping, same
plan limits, no separate auth surface. `[extensions.capabilities]
network_access = true` in each TOML allows that fetch; CORS for it is configured
in `config/cors.php` (`/api/*` only, bearer token, no cookies).

## Backend endpoints used here

| Endpoint | Used by | Notes |
|---|---|---|
| `GET /api/board` | modal | columns + members for the pickers |
| `GET /api/resources/search?type=&id=` | modal | resolves the linked object (non-PII fields only) |
| `GET /api/task-templates?type=order` | bulk | templates that fit the page + columns + team + plan headroom |
| `POST /api/tasks` | modal | one task |
| `POST /api/tasks/bulk` | bulk, block | see below |
| `GET /api/resource-tasks?type=order&id=` | block | open tasks with parsed checklist + done count |
| `PATCH /api/tasks/{id}` | block | used to tick a checklist line (rewrites the description) |
| `POST /api/tasks/{id}/complete` | block | toggles done |

`POST /api/tasks/bulk` is the one with behaviour worth knowing, because the
extension trusts it:

- **Idempotent per object.** If an order already has an *open* task with the same
  rendered title, it is skipped (`already_open`) — pressing the button twice is
  safe. That is also why `fillTemplateTitle()` in `public/js/app.js` and
  `TaskTemplates::renderTitle()` in PHP must keep the same `{date}` format
  (`d M Y`); a feature test pins it.
- **Template/type guard.** A template built for `order` refuses products, even
  if a caller sends them (`wrong_resource`).
- **Plan ceiling respected mid-batch.** It stops at the limit and returns
  `limited: true` instead of blowing past the Free plan's 50 open tasks.
- **WhatsApp pings are capped at 3 per batch** (`notify: true` + an assignee),
  so one click can never send thirty messages.
- Order numbers are fetched with **one** Admin GraphQL query for the whole
  batch (bulk selections only carry GIDs); if that call fails the tasks are
  still created, titled `order <id>` — visible, searchable, not blocked.

## Deploy (one-time ~15 min, local machine — NOT the server)

> Extensions are built by Shopify CLI and served from **Shopify's CDN**. Your
> shared hosting never runs any Node. You need Node ≥ 18 and the CLI on your own
> computer only for deploy/preview.

1. **Install the CLI:** `npm install -g @shopify/cli@latest`
2. **Link the app:** copy `shopify.app.toml.example` → `shopify.app.toml`, fill
   `client_id` + `application_url` (must match your production `APP_URL`), or run
   `shopify app config link`.
3. **Point the extensions at your API:** edit `extensions/shared/api.js` →
   `APP_URL = "https://app.yourdomain.com"` (no trailing slash).
4. **Deploy the backend first** — `public/js|css`, `app/`, `routes/api.php`,
   `config/task_templates.php`, then `php artisan config:clear`. The extensions
   call endpoints that live there; deploying extensions against an old backend
   shows up as 404s inside the modal.
5. **Install extension deps:**
   ```bash
   for d in extensions/taskpe-*/; do (cd "$d" && npm install --omit=dev); done
   ```
6. **Build/verify locally** (this is the real syntax check for the JSX — the app
   itself has no build step, the extensions do):
   ```bash
   shopify app build      # or `shopify app dev` to click through on a dev store
   ```
7. **Set `include_config_on_deploy = true`** under `[build]` in `shopify.app.toml`
   if you want Dashboard config managed from the file.
8. **Deploy:** `shopify app deploy` → creates a version. Partner Dashboard → your
   app → **Versions** → release it.
9. **Use it:**
   - any order → **More actions** → **Create task**;
   - Orders list → tick rows → **Create TaskPe tasks**;
   - any order page → **Add custom app block** → *TaskPe — this order* (pin it;
     it is per-merchant by Shopify's design).

Preview while developing: `shopify app dev` (watch mode) — it serves all six
extensions into your dev store's admin, including the block.

## Notes

- Keep `api_version` in each `shopify.extension.toml` equal to
  `SHOPIFY_API_VERSION` in your `.env` (bump together, quarterly), and bump the
  extension deps with it: `npm install @shopify/ui-extensions@latest` per folder.
- The template list the bulk modal and the order-page card offer comes from
  `config/task_templates.php` — add a template there and it appears in the board
  picker, the bulk modal and the card, no extension change needed. Only give a
  template a `resource_type` if it should appear for that object type; generic
  chores (no `resource_type`) are deliberately *not* offered next to a bulk
  selection, so a weekly task can't be filed 30 times in one click.
- If the CLI build ever complains about a `../../shared/…` import, copy that
  shared file next to the extension's `src/*.jsx` and change the import to
  `./File.jsx` (per-extension vendoring; keep the copy in the folder README).
- `t:name` / `t:description` in each TOML resolve through that extension's
  `locales/*.json`, so new UI strings go there, not into the JSX. Unknown keys
  fall back to the literals in `shared/*.jsx`.
- Nothing here is secret: `APP_URL` is public, the session token is minted per
  admin user by Shopify, and every endpoint re-scopes to that tenant.
