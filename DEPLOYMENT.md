# Deploying TaskPe on shared hosting (cPanel / LiteSpeed / Apache)

Zero build step: upload the repo, point the document root at `public/`, set `.env`,
migrate. Everything below is ordered by "how often it bites".

---

## 1. First-time install

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env                      # then edit — see the table below
php artisan key:generate
php artisan migrate --force               # creates shops/members/columns/tasks/…
php artisan storage:link                  # optional (no public uploads today)
php artisan config:cache                  # cPanel often hides .env from PHP otherwise
php artisan migrate:status                # every 2026_09_29_* row must be "Ran"
```

**Do not look for a frontend build step on the server — there is none.** No
`npm install`, no `npm run build`, nothing in `public/build` or `public/hot` is read
there: `app.blade.php` loads `asset('js/app.js')` + `asset('css/app.css')` directly, so
uploading those two files *is* the deploy, and Node need not exist on the host at all.
(The one `npm install` in this project runs on **your** machine, at the repo root, and
only to build the Admin extensions — see `extensions/README.md`.) A leftover `node_modules/` (from Laravel's default
`composer dev`, which used to start Vite) is dead weight — safe to delete, and the
`public/build` / `public/hot` entries in `.gitignore` are only there in case one is
ever added.

Cron (one line, runs the queue + digests + weekly chores):

```
* * * * * cd /home/USER/taskpe && php artisan schedule:run >> /dev/null 2>&1
```

## 2. The `.env` values that make or break the board

| Key | Must be | If it is wrong |
|---|---|---|
| `APP_URL` | exactly the public origin, e.g. `https://taskpe.example.in`, **no trailing slash**, `https` | OAuth `redirect_uri` mismatch during install; `asset()` links CSS/JS to another host → white page |
| `APP_KEY` | output of `php artisan key:generate` | 500 the moment a shop row is read — shop tokens and Whatify keys are `encrypted` casts |
| `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` | Partner Dashboard → Client credentials | Session tokens fail `aud`/signature verification → 401 → the SPA cannot load the board |
| `SHOPIFY_API_VERSION` | same version as in the Partner Dashboard | webhook + Admin API calls 400 |
| `DB_*` | the cPanel database, user and password | `SQLSTATE[...]` — the board cannot load |
| `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=file` | as in `.env.example` | `cache` table missing → 500 on unrelated requests |

`/` (the app shell) now checks APP_KEY / API key / API secret / APP_URL and the
existence of the `shops` table and prints what is missing, so a misconfigured
box says so out loud instead of showing a broken board.

## 3. Apache/LiteSpeed must not eat `Authorization`

Embedded apps authenticate every XHR with a Bearer session token. CGI/FastCGI/
LSAPI SAPIs strip that header before PHP runs, so a *valid* token looks like
"no token" and the board never loads.

`public/.htaccess` carries the workarounds (do not delete them):

```apache
<IfModule mod_setenvif.c>
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
</IfModule>
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

`VerifyShopifySessionToken` then reads, in order: `Authorization`,
`X-TaskPe-Auth` (the twin the SPA also sends), `$_SERVER['HTTP_AUTHORIZATION']`,
`$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`.

If you control the vhost, the real fix is one directive (Apache ≥ 2.4.13):

```apache
CGIPassAuth On
```

Do **not** paste `CGIPassAuth On` into `.htaccess` on an older Apache — an
unknown directive there 500s the whole vhost.

Check it from your machine:

```bash
curl -s -H 'Authorization: Bearer a.b.c' https://taskpe.example.in/api/board
# {"error":"invalid_session_token",...}  → header arrives (good)
# {"error":"missing_session_token",...}  → header stripped (fix .htaccess / CGIPassAuth)
```

## 4. Permissions

`storage/` and `bootstrap/cache/` must be writable by PHP:

```bash
chown -R cpaneluser:cpaneluser storage bootstrap/cache
find storage -type d -exec chmod 775 {} \;
```

Writable failure looks like a 500 on `/`, not a permission error.

## 5. Troubleshooting: what each screen means

The SPA never prints a generic sentence any more — the text you see *is* the
diagnosis.

| Screen | Meaning | Do this |
|---|---|---|
| "Open TaskPe from your Shopify admin" + store-domain field | URL opened outside `admin.shopify.com`, so there is no session token. Not a bug. | Open Apps → TaskPe in the admin, or type the store domain there to run OAuth. Teammates: `/staff`. |
| same screen, mentioning **stripping the Authorization header** | App Bridge *did* produce a token; PHP never received it | § 3 |
| same screen, "could not verify your Shopify session" | Token arrived but failed JWT checks | `SHOPIFY_API_KEY`/`SECRET`, and `APP_URL` must equal the origin the app is served from (tokens are origin-bound) |
| "Shopify no longer accepts the access token this install is holding" | our stored grant was revoked or is undecryptable — see § 9 | `php artisan taskpe:doctor <domain>`, then reconnect (Apps → TaskPe) |
| "The board could not be loaded" + an SQL message | DB not migrated or not reachable | `php artisan migrate:status`, `DB_*`, `php artisan config:clear` |
| "The board could not be loaded" + HTTP 419 | CSRF on a POST | only `webhooks/*` and `staff/*` are exempt; API calls are bearer-authenticated and must not be proxied with cookies |
| "The board could not be loaded" + *Cannot reach the TaskPe server* | Blocked mixed content (app served over http) or the domain does not resolve | force https, fix `APP_URL` |
| Blank page, CSS missing | `APP_URL` points at another host | § 2, then `php artisan config:clear` |
| Shopify shows "App couldn't load" | Embedded = OFF, or allowed redirect URL missing | Partner Dashboard: **Embedded in Shopify admin = ON**, redirect `https://APP_URL/auth/shopify/callback` |
| "TaskPe is not configured on this server yet" | § 2 items are empty | fill `.env`, `php artisan config:clear` |

Logs, always: `storage/logs/laravel.log`. On a 500, tail it while reloading the app:

```bash
tail -f storage/logs/laravel.log
```

## 6. Sanity checks after a deploy

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://taskpe.example.in/up            # 200 = framework alive
curl -s https://taskpe.example.in/api/board                                        # JSON 401 = routing + middleware alive
curl -s https://taskpe.example.in/privacy | head -5                                # HTML renders, views writable
curl -s https://taskpe.example.in/api/task-templates?type=order  # 401 JSON = extension endpoints deployed; 404 = old routes/api.php
curl -s -o /dev/null -w '%{http_code}\n' https://taskpe.example.in/team     # 200 = section paths serve the shell (the admin sidebar links to these)
php artisan taskpe:doctor demo.myshopify.com   # install state, token, config, live API probe, webhooks
php artisan taskpe:demo-store demo.myshopify.com --force   # fill a dev store's board
php artisan test                     # PHP suite (auth boundary, tenancy, bulk creation, billing, …)
node tests/js/boot.smoke.mjs         # SPA boot screens for each failure mode
```

## 7. Admin extensions (actions, bulk action, order-page block)

`extensions/` is built and hosted by Shopify, never on this server — see
`extensions/README.md` (`shopify app deploy`). They call the same `/api/*`
endpoints, so § 3 applies to them too.

When you ship both halves, **order matters**: the bulk action
(`POST /api/tasks/bulk`) and the order-page card (`GET /api/resource-tasks`,
`GET /api/task-templates`) are backend routes. Upload `routes/api.php`,
`app/Services/TaskTemplates.php`, `app/Http/Controllers/Api/TaskController.php`,
`app/Services/CodAutoTask.php` and `public/js/app.js` + `public/css/app.css`,
run `php artisan config:clear`, and only then `shopify app deploy`. A 404 inside
the bulk modal means the extensions were deployed against an older backend — not
that Shopify rejected the extension.

## 8. Section paths (the app menu in Shopify's sidebar)

TaskPe has no in-app nav strip: **Board · Team · Settings · Plan** are menu items
in the admin's own left sidebar. App Bridge reads a `<ui-nav-menu>` element that
`public/js/app.js` mounts, and each entry is a path Laravel serves from the same
shell (`/`, `/team`, `/settings`, `/plan` — see `AppController::SECTIONS`).

* **No new `.env` keys and nothing to deploy to Shopify.** This is routing plus a
  web component, not an extension — `php artisan config:clear` and the updated
  `public/js/app.js` are the whole change.
* **`/team` must return the SPA, not a 404.** That is the same
  `RewriteCond … !-f / !-f → index.php` rule in `public/.htaccess` the app already
  depends on. A host that 404s on `/team` (rare; usually an old `RewriteBase`
  experiment) shows up as sidebar links doing nothing while `Apps → TaskPe` still
  works — fix the rewrite, don't add a nav bar back.
* **The sidebar is only inside the admin.** Opened as a plain link there is no
  App Bridge to read the menu, which is why `/` still renders the "open this from
  your Shopify admin" gate, and staff keep using `/staff`.
* If Shopify ever shows the section list twice, the culprit is a stale cached
  `app.js` still mounting the old in-app tab strip: hard-reload the app frame
  (or bump the asset version) before touching anything else.


## 9. `Invalid API key or access token (unrecognized login or wrong password)`

The one log line that looks like a credential bug and is almost never one. `X-Shopify-Access-Token`
is sent from `shops.access_token`, so Shopify is rejecting **the token stored for that row** —
and all four causes need a different fix:

| Cause | How it happens | Fix |
|---|---|---|
| token revoked | the app was uninstalled, or reinstalled/accepted again on the store | reconnect once: **Apps → TaskPe**, or `https://APP_URL/auth/shopify?shop=<domain>` in the browser (top level, not in the admin tab) |
| wrong `APP_KEY` | the store's row was created on another server (hosting move), or `php artisan key:generate` was re-run — `access_token` is **encrypted with APP_KEY** | restore the old `APP_KEY`, or reconnect to mint a fresh one. Never rotate `APP_KEY` without telling merchants to reinstall |
| different app | `.env` `SHOPIFY_API_KEY`/`SECRET` belong to app A while the store installed app B (common after `shopify app deploy` from a copied `shopify.app.toml`), or a stale `config:cache` is serving the old values | set the pair from the app the merchant installed, then `php artisan config:clear` |
| OAuth never finished | the row exists (an old install, a copy) with an empty token | reconnect |

```bash
php artisan taskpe:doctor                      # every shop
php artisan taskpe:doctor house-of-indha.myshopify.com
php artisan taskpe:doctor myshop.myshopify.com --register   # re-subscribe webhooks too
```

The doctor prints the config actually in use (`client_id` prefix, `APP_URL`, `api_version`), the
row's state, the token as `sha256:xxxxxxxx/50ch` — **never the value** — then asks Shopify itself
with `{ shop { name myshopify_domain } }`. That last probe catches the sneaky variant: a token
that decrypts fine but answers for *another store* (copied `shops` row). `undecryptable(APP_KEY
changed)` as the fingerprint is the second row of the table above, diagnosed without a stack trace.

What the app does on its own: the first rejection marks the shop (`uninstalled_at` + a reason in
`settings.auth.rejected_*`), so

* the board shows the **reconnect** sentence instead of a spinner, and the SPA's existing
  `not_installed` handling restarts OAuth at top level — usually one click, no SSH needed;
* webhook registration **skips** that shop instead of logging one 401 per topic (that spam was
  the old symptom of exactly this state);
* `taskpe:send-digests` and `taskpe:recurring-chores` pass it over, because both filter on
  `uninstalled_at` — no point queueing WhatsApp for a store we cannot read.

A successful OAuth callback clears the flag (`Shop::clearTokenRejection()`), so after reconnecting,
`php artisan taskpe:doctor` should be all green with no extra step.
