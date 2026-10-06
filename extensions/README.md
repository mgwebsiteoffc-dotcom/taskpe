# TaskPe — Shopify Admin "Create task" extensions

Adds **"Create task"** to the *More actions* menu on these Shopify Admin pages:

| Extension folder | Admin page | Target |
|---|---|---|
| `taskpe-task-order` | Order details | `admin.order-details.action.render` |
| `taskpe-task-draft-order` | Draft order details | `admin.draft-order-details.action.render` |
| `taskpe-task-product` | Product details | `admin.product-details.action.render` |
| `taskpe-task-customer` | Customer details | `admin.customer-details.action.render` |

Each click opens a modal that creates a task on your TaskPe board with the
order/product/customer **pre-linked**, an assignee, priority and due date —
without leaving the page. (Blog posts have no admin-action target in Shopify —
link them from inside the app instead: search or paste the article URL.)

The shared form lives in `shared/CreateTaskAction.jsx` — edit it once, all
four extensions pick it up.

## How it authenticates
The extension (running inside Shopify's sandbox) requests a **session-token
JWT** (`shopify.auth.idToken`) and calls the same `/api/*` backend as the
embedded app (`VerifyShopifySessionToken` middleware). `[extensions.capabilities]
network_access = true` in each TOML allows that fetch; CORS on the API is
configured in `config/cors.php`.

## Deploy (one-time ~15 min, local machine — NOT the server)

> Extensions are built by Shopify CLI and served from **Shopify's CDN**.
> Your shared hosting never runs any Node. You need Node ≥ 18 and the CLI
> on your own computer only for deploy/preview.

1. **Install the CLI:** `npm install -g @shopify/cli@latest`
2. **Link the app:** in the project root, copy `shopify.app.toml.example` →
   `shopify.app.toml`, fill `client_id` + `application_url`
   (must match your production `APP_URL`). Or run `shopify app config link`.
3. **Point the extension at your API:** edit
   `extensions/shared/CreateTaskAction.jsx` → `APP_URL = "https://app.yourdomain.com"`.
4. **Install extension deps:**
   ```bash
   for d in extensions/taskpe-task-*/; do (cd "$d" && npm install --omit=dev); done
   ```
5. **Set `include_config_on_deploy = true`** under `[build]` in
   `shopify.app.toml` if you want Dashboard config managed by the file.
6. **Deploy:** `shopify app deploy` → creates a version.
   Go to Partner Dashboard → your app → **Versions** → release it.
7. **Use it:** open any Order / Draft order / Product / Customer in admin →
   **More actions** → **Create task**.

Preview on a dev store while developing: `shopify app dev` (watch mode) —
click through from the printed URL, open an order → More actions → Create task.

## Notes
- Keep `api_version` in each `shopify.extension.toml` equal to
  `SHOPIFY_API_VERSION` in your `.env` (bump together, quarterly).
- If the CLI build ever complains about the `../../shared/…` import, copy
  `extensions/shared/CreateTaskAction.jsx` next to that extension's
  `src/ActionExtension.jsx` and change the import to `./CreateTaskAction.jsx`.
- Extension deps bump quarterly with Shopify releases:
  `npm install @shopify/ui-extensions@latest` inside each extension folder.
