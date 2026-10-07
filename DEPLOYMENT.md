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
php artisan migrate:status                # every 2026_* row must be "Ran" (a later
                                            # upload that ships a migration needs `migrate --force` too)
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

TaskPe has no in-app nav strip: **Dashboard · Board · Team · Settings · Plan** are menu
items in the admin's own left sidebar. App Bridge v4 reads an `<s-app-nav>` element whose
`<s-link>` children `public/js/app.js` mounts, and each entry is a path Laravel serves
from the same shell (`/dashboard`, `/board`, `/team`, `/settings`, `/plan` — see
`AppController::SECTIONS`).

A sixth link, `rel="home"`, is hidden from that list on purpose: it points at bare `/`,
which asks for no section, so `app.js` picks by role — an **owner** who opens the app from
its sidebar name lands on the **dashboard**, everyone else on the **board**. Every visible
entry states its section, so the role default can never fight a deliberate click.

* **No menu in the sidebar at all?** Two causes, in this order:
  1. *Stale `app.js`.* The assets are cache-busted by file mtime (`app.css?v=…`,
     `app.js?v=…` from `app.blade.php`), so an upload changes the URL and the frame
     re-fetches. If someone pinned a version instead, hard-reload the app frame.
  2. *Wrong element.* `cdn.shopify.com/shopifycloud/app-bridge.js` is App Bridge v4,
     which **ignores the retired `<ui-nav-menu>`/`<a>` pair** — no console error, just
     an empty sidebar. Declare `<s-app-nav>` with `<s-link href rel="home">`. Check from
     inside the frame: `document.getElementById('taskpe-app-nav')?.outerHTML` should
     show `S-APP-NAV` with six `S-LINK` children (home + the five sections), and
     `display: none` (it is configuration; the admin paints the real menu).

* **No new `.env` keys and nothing to deploy to Shopify.** This is routing plus a
  web component, not an extension — `php artisan config:clear` and the updated
  `public/js/app.js` are the whole change.
* **`/team` (and `/dashboard`) must return the SPA, not a 404.** That is the same
  `RewriteCond … !-f / !-f → index.php` rule in `public/.htaccess` the app already
  depends on. A host that 404s on `/team` (rare; usually an old `RewriteBase`
  experiment) shows up as sidebar links doing nothing while `Apps → TaskPe` still
  works — fix the rewrite, don't add a nav bar back.
* **The sidebar is only inside the admin.** Opened as a plain link there is no
  App Bridge to read the menu, which is why `/` still renders the "open this from
  your Shopify admin" gate, and staff keep using `/staff`.
* If Shopify ever shows the section list twice, the culprit is a stale cached
  `app.js` still mounting the old in-app tab strip. The mtime cache-buster above is
  what prevents that class of confusion — do not replace it with a fixed `?v=`.


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

## 10. `ACCESS_DENIED` on the `orders` field (protected customer data)

```
local.WARNING: Shopify GraphQL errors {"shop":"house-of-indha.myshopify.com",
 "errors":"[{\"message\":\"This app is not approved to access the Order object ...\",
 \"extensions\":{\"code\":\"ACCESS_DENIED\",\"path\":[\"orders\"]}}]"}
```

This is **not** the section above: the token authenticated (a dead token is a 401), Shopify refused
the *field*. An app may read a store's orders only as far as its **Protected customer data** approval
allows, and `read_orders` in `.env` is not that approval — a scope is what the app asks for, this is a
review the *app* has to pass (Partner Dashboard → your app → **API access → Protected customer data**
→ request `read_all_orders`). Until that is granted, an offline token can read orders created **after
the install** and nothing older.

Where it reached TaskPe: linking a task to an order (`Api\ResourceSearchController::searchOrders`)
searched `orders(first: 10, query: "name:*#1001*")` with no date bound, so any pre-install order number
raised instead of returning an empty list, and the modal said "Search failed".

Now:

* `Shop::canReadAllOrders()` reads the granted scopes (falling back to `SHOPIFY_SCOPES`), and
  `Shop::orderSearchSince()` returns the install date when `read_all_orders` is absent. The order
  search then appends `created_at:>=<install date>` — a query Shopify *will* answer — and the response
  carries a `note`, which the "Link a Shopify object" modal prints under the results ("Showing orders
  created since Taskpe was installed (2026-09-20)"). An unreachable order reads as out of reach, not
  as "does not exist".
* The bound is only a guess from the scope list, so a denial inside it gets **one unbounded retry**
  before anything surfaces; that covers a store approved after `shops.scopes` was last synced.
* An exact lookup (`?id=`, i.e. a pasted admin URL) stays unbounded. If even that is denied, the
  message names the review instead of a stack trace (`hintFor()`).
* Draft orders, products and blog posts are untouched — they are not protected objects — and customer
  search keeps selecting only `name`, the one PCD field justified in the listing.

No `.env` value fixes it. After Shopify approves the scope, add `read_all_orders` to the app's scopes
(Partner Dashboard **and** `shopify.app.toml`; `SHOPIFY_SCOPES` is the env twin), re-install on the
store, and the note disappears by itself because `orderSearchSince()` re-reads `shops.scopes`.


## 11. One task database, three views (Notion's shape, Shopify's chrome)

One segmented control — **Board · List · Month** — under the page title; all three read the same
task set. There is deliberately nothing else in that row: no sort, no group-by, no fold. Sorting
was removed because the order is already decided by the work (open before done, soonest date
first), and every control that only half the people use is a control the other half has to
ignore. `List` is what `Table` used to be, minus the machinery.

| view | what it is for | what is in it |
|---|---|---|
| Board | the queue | one tinted well per column, white cards inside, and a totals line (`2 open`, `1 late`, `1 without an owner`; a done column reads `5 done · 3 this week`). A card shows its date as words (`2 days late`, `Due tomorrow`, `No date`) and a real **Done** button |
| List | everything at once | rows grouped by column, open first and then by soonest date; owner, team, priority word and an **Open** button. Nothing to click in a heading |
| Month | planning ahead | Monday-first grid with `‹ October 2026 ›`, **This month** to get back, and one amber line for tasks that were already late before the 1st — a month grid hides last month's misses by construction, so it says so |

The count tiles above the bar (`Open`, `Late`, `Due today`, `No owner`, `Done today`) are the only
filter: click one and the board shows just those, with a `Show all` way back. `d` hides the tiles
for people who want the board alone.

Column settings are one **Edit** button per column, visible only to the owner, opening a sheet with
the name, the team and the done-stage tick — plus delete. It replaced three icon-only buttons that
appeared on hover: invisible on a phone, and a guess for anyone who does not live in the app.

Nothing is stored for any of this: the mode lives in `localStorage` (`taskpe_mode`) like
`taskpe_dash`, so there is no route, no column, no migration. Deep links keep working — `?view=`
picks the *section* (dashboard/board/team/settings/plan), the view mode is a within-section choice.

To look at it without touching the store: open **`ui-preview.html`** from a checkout in any
browser. It loads the real `public/js/app.js` + `public/css/app.css` against a stubbed
`/api/board`, so the design can be judged offline, and it never ships (only `public/` is
served). Fake data, real code — including the drawer, the templates modal and `?`.

## 12. Teams on the dashboard (Accounting, Warehouse, …)

`columns.team` is a free-text tag: which part of the shop works that column. It is the
only new state the dashboard needs — nothing else is configured per team.

```bash
php artisan migrate --force      # 2026_10_06_000001_add_team_to_columns_table
```

* **Set it on the board**: **Edit** on a column header (owner only, always visible) → type
  `Accounting`, `Warehouse`, `Fulfilment`… Existing names are suggested, so one team is
  spelled one way. Empty = no team.
* **What it drives**: the dashboard's **Teams** panel (open vs closed this week per team,
  oldest item, who is on it) and clicking a team narrows the board to that team's columns
  — the count tiles above the board follow the same filter, so "Warehouse: 2 open, 1 late"
  is one click, not a manual scan.
* **Columns with no tag** are not hidden: the panel ends with "N open in M columns with no
  team". A half-tagged board has to report itself, otherwise the team rows silently fail to
  add up to the totals next to them.
* Members are *not* assigned to teams — a task belongs to the team of its column, which is
  how a small shop actually works (the same person answers for COD calls and dispatch).
