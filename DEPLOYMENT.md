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
curl -s https://taskpe.example.in/api/teams                          # 401 JSON = team routes live; 404 = routes cached → php artisan route:clear
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
and all five causes need a different fix:

| Cause | How it happens | Fix |
|---|---|---|
| token revoked | the app was uninstalled, or reinstalled/accepted again on the store | reconnect once: **Apps → TaskPe**, or `https://APP_URL/auth/shopify?shop=<domain>` in the browser (top level, not in the admin tab) |
| wrong `APP_KEY` | the store's row was created on another server (hosting move), or `php artisan key:generate` was re-run — `access_token` is **encrypted with APP_KEY** | restore the old `APP_KEY`, or reconnect to mint a fresh one. Never rotate `APP_KEY` without telling merchants to reinstall |
| different app | `.env` `SHOPIFY_API_KEY`/`SECRET` belong to app A while the store installed app B (common after `shopify app deploy` from a copied `shopify.app.toml`), or a stale `config:cache` is serving the old values | set the pair from the app the merchant installed, then `php artisan config:clear` |
| OAuth never finished | the row exists (an old install, a copy) with an empty token | reconnect |
| **the token is the old, non-expiring kind** | the store installed before Shopify required public apps to hold an *expiring* offline token — the Admin API refuses the permanent one (`Non-expiring access tokens are no longer accepted for the Admin API`). Looks identical from the log; it is not a credential bug at all | `php artisan taskpe:tokens --rotate` converts it in place (§ 19). A reconnect works too, and is what the merchant sees if nobody has SSH |

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
`settings.auth.rejected_*`), so (for the last row of the table it first tries to fix itself, which
§ 19 explains)

* the board shows the **reconnect** sentence instead of a spinner, and the SPA's existing
  `not_installed` handling restarts OAuth at top level — usually one click, no SSH needed;
* webhook registration **skips** that shop instead of logging one 401 per topic (that spam was
  the old symptom of exactly this state);
* `taskpe:send-digests` and `taskpe:recurring-chores` pass it over, because both filter on
  `uninstalled_at` — no point queueing WhatsApp for a store we cannot read.

A successful OAuth callback clears the flag (`Shop::clearTokenRejection()`), so after reconnecting,
`php artisan taskpe:doctor` should be all green with no extra step.

## 10. `ACCESS_DENIED` on the `orders` field (protected customer data)

> If the question is "why can't I find an order from before we installed the app", read § 15
> first: it is the same rule, and there is now a `.env` switch for it (`SHOPIFY_READ_ALL_ORDERS`),
> a three-step fix in the picker's own note, and a route that needs no permission at all.

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
  search selects only `displayName` + `numberOfOrders`, the one PCD field justified in the listing.
* What the picker says for each cause, and the three grammar bugs this replaced, are in § 13.

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

**Width**: the dashboard and the board are capped at 1560px and centred, and the panels
flow `auto-fit minmax(400px,1fr)` with the chart panel spanning two. A 1900px screen used to
get a 1220px column pinned to the left with a third of the page empty, which reads as a broken
layout rather than as air; the grid now fills it, and drops to two columns then one as the width
goes. Panels in a row stretch to the same height, because boxes that end at different lines look
unfinished even when the numbers inside them are identical.

Nothing is stored for any of the view choices: the mode lives in `localStorage` (`taskpe_mode`)
like `taskpe_dash`, so there is no route, no column, no migration for them. Deep links keep working — `?view=`
picks the *section* (dashboard/board/team/settings/plan), the view mode is a within-section choice.

To look at it without touching the store: open **`ui-preview.html`** from a checkout in any
browser. It loads the real `public/js/app.js` + `public/css/app.css` against a stubbed
`/api/board`, so the design can be judged offline, and it never ships (only `public/` is
served). Fake data, real code — including the drawer, the templates modal and `?`.

## 12. Teams (Accounting, Warehouse, …) — the list, and the tag on a column

Two halves, and they answer different questions:

* **the list** — the departments this shop recognises. Stored as `teams` in the shop's JSON
  `settings`, so there is no table and no migration for it; `TeamController` owns add / rename /
  remove and caps the list at 12.
* **the tag** — `columns.team`, free text on a column, saying which of those departments works it.

```bash
php artisan migrate --force      # 2026_10_06_000001_add_team_to_columns_table  (the tag)
php artisan route:clear         # /api/teams is new; a cached route file would 404 it
```

* **Name them**: **Team → Teams → Add a team**. A team may exist before any work uses it — that
  gap is the useful finding, and such a team shows on the dashboard as `nothing tagged yet`
  rather than being invisible.
* **Rename** retags every column carrying the old spelling in the same request, because a rename
  that leaves columns behind splits one department down the middle of every chart.
* **Remove** is refused while columns still use the name (`2 columns still use Store…`) rather
  than quietly untagging them and making that work disappear from the dashboard.
* **Set the tag on the board**: **Edit** on a column header (owner only, always visible) → type
  `Accounting`, `Warehouse`, `Fulfilment`… The shop list is offered as suggestions, and a name you
  type that is not on it is filed as a new team, so the vocabulary stays in one place. Empty = no team.
* **What it drives**: the dashboard's **Teams** panel (open vs closed this week per team,
  oldest item, who is on it) and clicking a team narrows the board to that team's columns
  — the count tiles above the board follow the same filter, so "Warehouse: 2 open, 1 late"
  is one click, not a manual scan.
* **Columns with no tag** are not hidden: the panel ends with "N open in M columns with no
  team". A half-tagged board has to report itself, otherwise the team rows silently fail to
  add up to the totals next to them.
* Members are *not* assigned to teams — a task belongs to the team of its column, which is
  how a small shop actually works (the same person answers for COD calls and dispatch).

## 13. Order search: why it used to say "Search failed — try again."

The picker in the task drawer (`/api/resources/search`) builds one string that Shopify's
search parser has to accept, and three things about that string were wrong:

* **a leading wildcard** — `name:*Ravi*`. Shopify only supports a `*` at the *end* of a term
  (a "prefix query"), so any order search that was not a number was a syntax error.
* **an unquoted date bound** — "Date values must be a string surrounded by quotes", so
  `created_at:>=2026-09-01` is not valid while `created_at:>='2026-09-01'` is. That bound is on
  *every* order search for a store whose `read_all_orders` is not approved, which is most stores:
  the search failed before Shopify looked at the term at all.
* **an unquoted value** — `#`, quotes, colons, parens and `+`/`-` are syntax, so `#1042` and
  `O'Brien` could not be searched.

Now `ResourceSearchController` sanitises the term, quotes what needs quoting, puts `*` only at the
end, and tries a short list of queries narrowest-first (`name:'#1042'`, then a full-text
`'1042'`) so a store whose order names are not plain numbers still finds its order.

The message the merchant sees is chosen from the actual cause, and **"try again" is only said when
a retry can help**:

| what Shopify said | what the picker shows |
|---|---|
| `ACCESS_DENIED` / protected customer data | the Partner Dashboard instruction (§ 10) — no retry button |
| timeout, 429, 5xx, connection | "Shopify is busy or slow right now." + **Try again** |
| `USER_ERROR` / invalid query | "Shopify could not read that search. Try just the order number, for example 1042." |
| a PHP fault of ours (`must be of type`, `TypeError`, `undefined method`) | "TaskPe hit an error in this search — nothing you did wrong." + the log pointer, no retry button |
| anything else | "Shopify could not answer this search — try again in a moment." |

The raw GraphQL reply never reaches the browser as advice, but it is logged
(`Resource search failed`, grep `laravel.log` for it — at `error` level when the message looks
like a bug of ours, so it also reaches whatever log alerting reads that level) and returned as
`detail` only while `APP_DEBUG=true`, where the picker shows it under the sentence in small grey
text.
Searching one character is refused in the UI instead of returning half the store, and a
zero-result search says *what* found nothing ("Nothing matched that in Orders") with the
install-date note underneath, so "no matches" is never mistaken for "this order does not exist".

§ 14 is the sequel the live store forced: the *same* search path was also behind two unrelated-
looking errors on the order page, because a rejected search took the board fetch down with it.

---

## 14. One misplaced parenthesis, four complaints: the order page end to end

The store reported all of these in one message, minutes after § 13 went out:

* creating a task from the order page answered `The column id field is required.`
* the task that did get made read `Linked: order`, with no order number
* the picker still said the search failed
* the app opens to a lonely **T**

The log line settled it:

```
[2026-10-07 07:26:22] local.WARNING: Resource search failed
{"shop":"house-of-indha.myshopify.com","type":"order","q":"101",
 "error":"strtolower(): Argument #1 ($string) must be of type string, array given"}
```

### The cause

`Shop::canReadAllOrders()` lower-cased the wrong thing:

```php
collect(strtolower(explode(',', $granted)))   // strtolower() gets an ARRAY → TypeError
collect(explode(',', strtolower($granted)))   // what it was meant to say
```

PHP 8 raises a `TypeError` rather than shrugging, and `orderSearchSince()` calls this on
**every** order search — so the search died inside Laravel before Shopify was ever asked.
`hintFor()` matched no branch of a `strtolower` message, and the merchant got the
"try again in a moment" fallback (§ 13), which is the worst possible advice for a bug in
our own code: retrying a `TypeError` never helps.

### Why that turned into `The column id field is required.`

The Admin action fetched the board and the order in one `Promise.all`. The rejection
skipped *both* assignments, so `board` stayed `null` and the create POST carried
`column_id: undefined` — and Laravel refused to file a task on a store that has columns.
Two rules keep a failed lookup inside the one box it belongs to:

* **`POST /api/tasks` no longer demands a column.** `column_id` is nullable; a missing one
  resolves through `TaskTemplates::columnFor()` (that id → first non-done column → first
  column), which is what `bulk()` already did, so the two entry points no longer disagree.
  A store that deleted every column gets `Your board has no column to put this task in.
  Open TaskPe and add one first.` instead of a 500. `column_id` is written *after* the
  `...$data` spread, because a nullable-but-present key would otherwise insert `null` into
  a NOT NULL column.
* **the extension no longer couples the two calls.** The board loads on its own (its
  failure is the only one worth a red banner: it means TaskPe is unreachable); the order
  lookup loads on its own and its failure is silent, because all it can lose is a title.

### `Linked: order` with no number

Same cause: the number used to come only from the search. `TaskController::fillOrderTitle()`
now looks it up itself when `resource_type` is `order` and no title arrived, reusing the
bulk path's one-query `hydrateOrderTitles()` (best-effort by design — the pre-install data
scope can refuse it, § 10, and a task titled `order 1042` beats no task). The modal line
says `Linked to order #1042.` while that is unknown, rather than `Linked: order` — the
truncated-looking half-sentence that read as a bug.

### Our own faults are now named as ours

`hintFor()` has a branch for `must be of type` / `TypeError` / `undefined method` that says
so plainly, with no retry button, and the log line goes up to `error` level so a store that
alerts on it gets told. That is the whole difference between this message and the previous
one: it must send someone to `laravel.log`, not to the reload key.

### The lonely **T**

Both waiting screens were a brand letter and "Loading…". They now draw the thing that is
coming — four skeleton columns with cards, the store the board belongs to, and the same
layout the real board uses, so the swap barely moves — and the copy says *what* is being
loaded. `.skslow` (the "still waiting?" line + a plain Reload link, since a first embedded
load really can take a few seconds) is `opacity: 0` until an 8-second CSS animation reveals
it, so nobody is told something is wrong while the app is merely still working. It is
rendered twice, from `resources/views/*.blade.php` before `app.js` runs and from `bootView()`
after, deliberately with the same class names so the handover is invisible.

### Deploy

`app/`, `public/js/app.js`, `public/css/app.css`, `resources/views/`, then `php artisan
config:clear`. The `extensions/shared/CreateTaskAction.jsx` change needs
`shopify app deploy` **and** a released version (§ 7) — a deployed-but-unreleased version is
invisible to the store, which is also the answer to "why is it only in More actions": the
bulk action and the order-page block are separate extensions, and the block has to be pinned
by the merchant once (`extensions/README.md` has the three checks).

No migration, no `.env` key, no scope change. `npm run test:all` stays green.

---

## 15. The follow-up from the same store: `Shop` was the wrong class, and old orders

Two reports arrived after § 14 was deployed:

```
App\Http\Controllers\Api\TaskController::fillOrderTitle(): Argument #1 ($shop)
must be of type App\Http\Controllers\Api\Shop, App\Models\Shop given, called in …/TaskController.php on line 31

Shopify only lets this app read orders created since TaskPe was installed (2026-10-07),
so anything older than that cannot appear here.
```

### The first one is our fault, and it is older than § 14

`TaskController` has no `use App\Models\Shop;`, so every `Shop $shop` type hint in that file
resolved to `App\Http\Controllers\Api\Shop` — **a class that does not exist**. PHP keeps quiet
until something is actually passed, then refuses it. Two methods in the file take a `Shop`:

| method | what that meant |
| --- | --- |
| `hydrateOrderTitles()` | **every bulk create from a ticked Orders list threw**, before its own try/catch could help — the "best-effort" comment under it was never true |
| `fillOrderTitle()` (new in § 14) | creating a task from an order page threw the sentence the store pasted |

Fixed with the import, and `fillOrderTitle()` now swallows `\Throwable` around the lookup:
a title is a nicety, and nothing in that path may cost a merchant their task.

*The lesson the smoke test could not catch:* an unresolved type hint is silent until the call
site runs, and this repo has no PHP toolchain in CI. When a type hint names a class, the import
line is part of the code, not decoration — check it when copy-pasting a signature between
namespaces.

### "I want older orders here" — what the platform allows and what we added

The line the store quoted is not a TaskPe limitation we can switch off: Shopify's protected
customer data rule says an offline access token may read **only orders created after the
install**, until the app is granted `read_all_orders`. No query, sort key or quoting trick
changes that; the API refuses the rows.

What was missing is a way out, so the search now carries one:

* **The picker's note is actionable.** Under the sentence, the order tab shows *"Meanwhile:
  open the order in Shopify and use More actions → Create task. That links the order itself,
  so it works for old orders too."* Creating from the order page needs no read — the extension
  already holds the order id — so a task can be filed against any order, ever, on any store.
  Plus a labelled **"How to open the full order history"** button revealing the three steps
  (Partner Dashboard approval → `SHOPIFY_READ_ALL_ORDERS=true` + `config:clear` → reinstall).
* **`config/shopify.php` grew the switch.** `SHOPIFY_READ_ALL_ORDERS=true` appends
  `read_all_orders` to the requested scopes. It is off by default on purpose: Shopify refuses
  the scope at install until the review approves it, and a scope list that ends in a refusal
  means the merchant cannot install the app at all.
* **`Shop::canReadAllOrders()` no longer reads the config as a fallback.** It asks only what
  *this store's token* was granted. The config lists what we ask for, so with the switch on and
  the store not yet reinstalled it would have promised a full history the token cannot deliver —
  and the search would have come back as `ACCESS_DENIED` instead of the honest bounded answer.
  Unknown scopes now mean "not granted", which is the safe reading.

So: the approval is a Partner Dashboard action only the app owner can take — it is a **review**,
not a switch, and no deploy or code change can substitute for it. Everything after the approval
is one `.env` line, `php artisan config:clear`, and a reinstall. The note in the picker
disappears by itself, because it is computed from the granted scopes.

**Where it stands right now, in one command:**

```bash
php artisan taskpe:doctor house-of-indha.myshopify.com --skip-api
```

The `order history` row names the step that is still missing, in the same words as the notice:

| the row says | the state | what is left |
| --- | --- | --- |
| `every order, any date` | done | nothing |
| `we ask for read_all_orders but this store granted: …` | approved and configured, **this store hasn't reinstalled** | Apps → TaskPe → reinstall (or let the merchant open the app once from admin and accept the new permissions) |
| `orders created since <date> only — Shopify will not let this token read older ones until …` | never requested | the three steps below |

**The three steps, with the words to paste.** Partner Dashboard → **Apps** → *TaskPe* →
**API access** → *Protected customer data* → request **read access to all orders** (older than
the install). Shopify asks why. Something in this shape is what the review needs:

> The app creates follow-up tasks for store staff against specific orders (COD verification,
> non-delivery reports, refunds). Merchants routinely work on orders placed before the app was
> installed, so limiting reads to post-install orders breaks the core workflow: the merchant can
> see the order in their own admin but the app cannot link it. We query only `Order.id`, `name`,
> `created_at`, `display_financial_status`, `total_price` and line-item product titles — never
> customer name, address, email, phone or payment details — and we store nothing but the order
> number and title on the task record.

Then, once approved:

1. `.env` → `SHOPIFY_READ_ALL_ORDERS=true`, and if you deploy config from `shopify.app.toml`
   (`include_config_on_deploy = true`), add the scope there too —
   `scopes = "read_orders,read_products,read_customers,read_content,read_all_orders"`. The two
   must match: the CLI pushes its own list to the Dashboard, so a toml without the scope quietly
   removes it again on the next `shopify app deploy`.
2. `php artisan config:clear` on the server (otherwise the cached config still asks for the old list).
3. Each store **reinstalls** (or re-authorises from Apps → TaskPe) so its token is re-minted with
   the scope. Existing tokens never gain a scope retroactively — this is the step people miss, and
   the second row of the table above is exactly that state.

Until all three are done, order search stays bounded and says so. That is Shopify protecting
buyer data from every app by default, not a TaskPe setting we left off.

### Why the buttons still are not in the Orders list or on the order page

Three things must all be true, and only the first is ours:

1. `shopify app deploy` ran **after** `taskpe-task-order-bulk` and `taskpe-order-block` existed
   in `extensions/` (both were added in this branch, so any deploy predating them cannot show
   them), **and** that version was **released** in the Partner Dashboard. A deployed but
   unreleased version is invisible to the store.
2. The selection action only exists while rows are ticked: Orders → tick → the entry appears in
   the bar above the list. There is no per-row button in Shopify's Orders list, and no target
   for a button beside the order number (`extensions/README.md`, "What Shopify does *not* allow").
3. The block is pinned by the merchant: order page → **Add custom app block** → *TaskPe — this
   order*, once per store. Apps are not allowed to place blocks.

The second half of the store's complaint may also have been § 15's first half: even when the
bulk entry was visible, ticking rows and pressing it threw the `Shop` TypeError above, so
"nothing happened" looked like "the button is missing". Settings → **In your Shopify admin**
now says the deploy-half out loud too (deploy *and* release), instead of naming only the CLI.

### Deploy

`app/`, `config/shopify.php`, `public/js/app.js`, `public/css/app.css` → `php artisan
config:clear` (config changed — without this the new switch and the old scope list disagree).
No migration. Nothing to redeploy for this fix specifically: the PHP import is the whole
change, so no `shopify app deploy` is required for it.

`npm run test:all` green, plus 9 checks on the note (it must explain, must not offer a useless
retry, must stay quiet for products/customers, and its steps must toggle).

---

## 16. Older orders: linking what the app is not allowed to read

The bounded-search notice came back in a report — this time from the **template** flow, where
it is worse than in the picker: no result means no selection, and no selection means the
Create button stays disabled. The merchant was holding the order open in their own admin while
TaskPe claimed it could not find it.

So the notice now carries a way in, in both places: **Link an older order by number**. One
button, one field, and either of two answers:

* a typed number (`101`, `#101`) → stored as `resource_type='order'`, `resource_title='#101'`,
  **`resource_id` stays null**, and the card's link opens the merchant's own order list with
  that number in the search box (`/admin/…/orders?query=101`). Reading is the part Shopify
  refuses; pointing at their admin is not.
* a pasted admin link (`…/orders/6123456789`) → the id is *in* the URL, so it is stored as a
  normal link: `resource_id` set, GID and deep link built by `normalizeResource()`, and
  `fillOrderTitle()` may even resolve the number for a recent order.

### What makes this safe to store

An unverified link must not look verified. Three things say otherwise, deliberately:

| where | what the merchant sees |
| --- | --- |
| the task card | a grey **not checked** pill next to the order pill (`resource.verified === false`, which the API derives from `resource_id` being null — no new column) |
| the activity timeline | *"Linked order #101 by hand — this app may only read orders created since <date>, so nobody checked the number against Shopify"* with the actor's name and time |
| the staff board | the number as plain text, no admin link (they have no admin) |

Deriving "unverified" from `resource_id IS NULL` is the whole trick: there is no schema
change, and the state cannot disagree with itself — a link without an id *is* a link nobody
checked. The activity row is what a manager reads six weeks later when the number turns out
to be a typo.

Refused by the API, not silently accepted: `resource_ref` must match `/^#?\d{1,12}$/`, and an
`id` is only optional *when the ref is there* — otherwise the message is
"Pick a result from the list, or type the order number if Shopify will not let us read that
order." Both surfaces go through the same `PATCH/POST /api/tasks` body, so the extension, the
picker and the template flow cannot drift apart. `resource_ref` is unset inside
`normalizeResource()` because `store()` spreads the array straight into `create()` and it is
not a column.

### What it still cannot do

It cannot tell you the order's total, status, or whether it exists at all — those are reads,
and reads are the thing Shopify gates. That stays true until the `read_all_orders` approval in
§ 15; this change only stops the limit from blocking the *workflow*.

### Deploy

`app/Http/Controllers/Api/TaskController.php`, `app/Http/Controllers/Api/BoardController.php`,
`public/js/app.js`, `public/css/app.css`. No migration, no `.env` change, no extension redeploy.
`ui-preview.html` ships a hand-typed link on task 21 with the matching activity row, so the
look can be judged without a store (`?boot` still controls the fake latency).

---

## 17. Billing: the price on screen is Shopify's, in the store's currency

The complaint, in the merchant's words: the app was showing **₹499** for a plan whose price was
created **at Shopify**. So the number was neither Shopify's amount nor necessarily Shopify's
currency — a price the store would never be charged, wearing a currency it would never pay in.
Three separate things made that possible, and the rupee sign was the least of them.

1. `config/shopify.php → plans.*.prices` was the source of truth for money, keyed by currency:
   `{USD: 5.99, INR: 499}`. `BillingService::resolvePrice()` picks the store's currency if the
   map has it and **silently falls back to the USD amount** otherwise — so a CAD store is charged
   $5.99 and an INR store ₹499, both invented by this repo.
2. The Free-plan banner hardcoded `Starter (₹499/mo)` in `public/js/app.js` — a rupee figure for
   every store on every currency, whatever its plan actually cost.
3. The app created the charge itself with `appSubscriptionCreate`. For an app whose plans live in
   the Partner Dashboard (Shopify App Pricing) that mutation is the wrong API: Shopify creates the
   subscription when the merchant approves a plan on Shopify's own page, and an app that also
   creates one is asking for a second charge — and relaying Shopify's rejection as our advice.

### The switch

    SHOPIFY_BILLING_MODE=shopify      # plans/prices/trials created at Shopify (default)
    SHOPIFY_APP_PLANS_URL=            # optional: https://apps.shopify.com/<your-handle>

**`shopify`** — the config price table stops being the number the merchant is asked to pay. The app never
calls `appSubscriptionCreate` or `appSubscriptionCancel` (`BillingApiController::subscribe()` and `::cancel()`
answer **422 `billing_managed_by_shopify`** before anything is validated, and `BillingService::createSubscription()`
/ `::cancelSubscription()` carry the same guard for any other caller). The Plan tab still switches plans — the
segmented control, each card's button and "Move to Free" all stay, because a plan change must not need a support
ticket or a reinstall (1.2.3) — but every one of those actions ends in Shopify's own page: the merchant picks
and approves there, and the read-back of `currentAppInstallation` is what unlocks features here. Prices on the
cards are Shopify's too: the read-back amount for the plan a store is on, a labelled list price for the others
(`BoardController` strips `prices` out of the `plans` payload so the client cannot quote a number nobody will
bill). If Shopify publishes no handle to link to, `switch.kind` is `none` and the tab explains the admin path in
words (`Settings → Apps and sales channels → <app> → plan / billing`) instead of showing a button that goes
nowhere.

**`api`** — for an app that really does own its price table: the same cards, the same segmented control, but
the next click calls `appSubscriptionCreate` from this app with `config/shopify.php` prices in the store's own
billing currency, and the merchant approves inside Shopify's confirmation screen as before. With no price for
the store's currency the note says the US figure is used and Shopify converts it at its own rate.

The two roads are exclusive. An app whose Dashboard pricing model is Shopify App Pricing is not meant to
create charges through the Billing API at all (the hosted plan page is the supported path, and a charge created
there is what `currentAppInstallation` reports), and an app on "Set up your own pricing" has no
`.../pricing_plans` page for the link to open. `SHOPIFY_BILLING_MODE` is therefore not cosmetic: it is the app's
claim about which model the Dashboard is on, and the pair disagreeing is the cause of both plan-link failures in
this section. `php artisan taskpe:plans` prints the mode next to the links, so the disagreement is visible from
one command.

### What the Plan tab shows instead

`BillingService::readBilling()` reads the subscription back from the store:

    currentAppInstallation { activeSubscriptions {
      name status test trialDays createdAt currentPeriodEnd
      lineItems { plan { pricingDetails {
        ... on AppRecurringPricing { price { amount currencyCode } interval } } } } } }

and the tab renders exactly that — `TaskPe Starter · ₹499 every 30 days · renews 6 Nov 2026` —
so the amount and the currency come from the same record as the invoice and cannot drift from it.
Above the cards, in a panel called **What Shopify bills you**, with **Check again** (POST
`/api/billing/sync`, then a clean re-read of the board). The result is cached in
`shops.settings.billing` — a JSON column, no migration — and refreshed on install, on
`GET /billing/callback`, on any sync, and by that button.

Three honesty rules in that panel: a store whose subscription was never read shows *"Shopify could
not be read just now…"* and **no number**; a store with no active subscription is told *nothing is
being charged*; and a `test: true` subscription gets a **test charge** pill, because a store that
is paying nothing must not be shown a price as if it were real.

### Drift is logged, not displayed

`syncActiveSubscription()` compares both sources when it syncs:

    Billing: Shopify plan price differs from config/shopify.php plans
      {"shop":"…","plan":"starter","shopify":"4.99 USD","config":"499 INR","managed_by":"shopify (config is display-only)"}

That log line is the one to act on: fix the Dashboard plan (mode `shopify`) or the config table
(mode `api`). The merchant sees neither number nor argument — they see the invoice's amount.

### What still has to line up by hand

`Shop->plan` (which gates WhatsApp, member limits and the digest) is mapped from the subscription's
**name** — `stripos($name, 'growth')` / `'starter'`. On the Dashboard a merchant may name the plan
anything, so name them `TaskPe Starter` and `TaskPe Growth` (or keep the mapping in
`syncActiveSubscription()` in step with whatever they are called); otherwise Shopify bills and the
app still enforces Free limits. The key the app concluded is stored as `plan_key` inside
`shops.settings.billing`, so a wrong mapping is readable without guessing.

Limits of this change: it fixes who is believed about price, not what Shopify lets us read. The
renewal date and amount appear only after the first successful sync (an install that never synced
shows the "could not be read" line until **Check again** runs), and a plan created at Shopify with a
non-recurring or usage-based price shows its name and period with the amount left blank rather than
estimated — `AppUsagePricing` and one-time `AppOneTimePricing` are deliberately not summed into a
fake monthly figure.

### Deploy

`config/shopify.php`, `app/Services/BillingService.php`, `app/Http/Controllers/Api/BoardController.php`,
`app/Http/Controllers/Api/BillingApiController.php`, `app/Jobs/ProcessShopifyWebhook.php`,
`app/Console/Commands/DoctorCommand.php`, `public/js/app.js`, `public/css/app.css`, `.env.example`,
`ui-preview.html`. Set `SHOPIFY_BILLING_MODE=shopify` (the default if unset) and, if the app is
listed, `SHOPIFY_APP_PLANS_URL`. Then `php artisan config:clear` and `queue:restart` (the new
webhook topic is handled by a queued job). No migration — `shops.settings` is JSON.

**Per installed store**, the new topic has to be registered once, and the cache has to be filled once:

    php artisan taskpe:register-webhooks mystore.myshopify.com   # adds APP_SUBSCRIPTIONS_UPDATE
    php artisan taskpe:doctor mystore.myshopify.com              # the `billing` row shows what Shopify says

After that `taskpe:doctor` prints one `billing` row that answers both merchant questions at
once — who prices the store (`priced and billed by Shopify … appSubscriptionCreate refused here
on purpose`, or the `WARN` telling you the app still owns the price table) and what the store is
actually charged (`TaskPe Starter · 499.0 INR · renews 2026-11-06`, or *"nothing read from Shopify
yet — open the Plan tab and press "Check again""*).

No merchant-side action beyond that: cancelling, refunds and invoices stay with Shopify, which is
the point — the app cannot misquote a number it does not own.

---

### Choosing a plan from inside the app (rejection 1.2.3)

The App Store review came back with: *"While testing the Plan page, we clicked “View plans,” but the
app remains on the same route and provides no actionable way to upgrade from Free to Starter or
Growth."* That was true, and it was one empty `.env` line that had quietly turned every plan button
into prose: with Shopify-owned pricing the app must not create charges, so a plan button's only job is
to open **Shopify's hosted plan page** — and the URL for that came from `SHOPIFY_APP_PLANS_URL`, which
was unset. `renderPlan()` then fell back to a muted sentence ("Chosen on Shopify's plan page") on each
card. A reviewer cannot click a sentence, so the app failed on a thing it was built to support.

The page's address is documented and built from two handles:

```
https://admin.shopify.com/store/{store handle}/charges/{app handle}/pricing_plans
```

* `{store handle}` is the shop row's `handle`, taken from the domain during OAuth;
* `{app handle}` is `SHOPIFY_APP_HANDLE`.

so the app derives it per store (`BillingService::plansUrl($shop)`) instead of waiting for a
hand-written URL. `SHOPIFY_APP_PLANS_URL` still wins when set, for an app Shopify gave another path to.

| Piece | Where it lives | What it must be |
|---|---|---|
| `SHOPIFY_APP_HANDLE` | `.env` | Partner Dashboard → app → Settings → General → **App handle** (the same string as `handle` in `shopify.app.toml`). Empty ⇒ the Plan tab's buttons render disabled with a tooltip naming this variable and `/api/billing/subscribe` answers 422 with the same sentence — visible in the app, so nobody has to read a log to learn why a button is dead |
| Plan selection page | Shopify, inside the admin | Lists every plan **including Free**, so upgrade, downgrade and cancel are one page. The merchant picks; Shopify creates the subscription, prorates and invoices |
| Redirection URL | Partner Dashboard → App pricing → each plan | For an embedded app with an App Home, a relative path: **`/billing/callback`** (Shopify appends `plan_handle`). An absolute redirect URL also gets the shop domain appended |
| `SHOPIFY_BILLING_TEST` | `.env` | Meaningful only in `api` mode (legacy test charges). With App Pricing, development stores owned by the same partner organisation select any plan at **$0** — nothing to enable, and no card is needed to rehearse the flow |

What the return does, and why it does not trust the URL: `plan_handle` and `shop` arrive unsigned on a
top-level navigation, so `BillingController::callback()` uses them as hints about where to look and
decides from `currentAppInstallation.activeSubscriptions` on the store itself. Two details worth the
code they cost, both read out of the platform notes rather than guessed:

* **A relative redirect carries no store name.** Only absolute redirect URLs get the shop domain
  appended, so a merchant returning to `/billing/callback` would have landed on "Unknown shop" after
  doing everything right. `POST /api/billing/subscribe` therefore sets a 30-minute
  `taskpe_billing_return` cookie scoped to `/billing/callback` (httpOnly, Secure, SameSite=Lax — Lax
  still rides a top-level GET); the callback falls back to it and deletes it. If even that is missing,
  the merchant gets a plain page telling them to reopen the app, never a 404 with a code in it.
* **A plan that has not propagated is not a refusal.** `plan_handle` present with nothing active yet
  resolves to `billing=pending`: "Shopify is applying the change…" and one re-read a few seconds later.
  Before that, the race printed "Plan not approved" over an invoice the merchant had just signed.

App Pricing does not fire a webhook for plan changes — the redirect parameters are the signal — but
`app_subscriptions/update` still fires for Billing-API-created subscriptions, and both roads lead to
`syncActiveSubscription()`. **Check again** on the Plan tab is the manual hatch, and a board load
re-reads when the last sync is stale, so a change made while nobody had the app open lands without a
support ticket.

In `api` mode the same requirement has a mutation-level answer: `appSubscriptionCreate` now sends
`replacementBehavior: APPLY_IMMEDIATELY`, so Starter → Growth *replaces* the subscription with Shopify
prorating the difference, instead of stacking a second charge beside it; and the subscribe endpoint
accepts `free` in Shopify-priced mode, because a downgrade has to be a button too.

**Before replying in the feedback thread, run it on a development store**: Free → Starter (approve on
Shopify's page) → confirm the Plan tab shows Starter with Shopify's amount and that WhatsApp unlocks →
Starter → Growth → Growth → Free → then Shopify admin → Settings → Apps and sales channels → TaskPe →
Billing, and screenshot the charge history: "charges successfully processed in the application charge
history page" is the second half of what 1.2.3 checks. In the reply, name the click path — "Plan tab →
Choose Starter → Shopify's plan page → approve" — rather than the commit, because the reviewer re-tests
the flow.

### Switching a plan inside the app (and what the app is still not allowed to do)

The Plan tab has a **Switch plan** panel above the cards: one segment per plan (current one marked,
each showing its price), then a single action button whose sentence names what the next click does.
Four outcomes exist and the server picks which one it is — `planCatalog()` puts a `switch` on every
plan, so the SPA never guesses:

| `switch.kind` | When | What the click does |
|---|---|---|
| `shopify-plan` | App Pricing **and** this store knows that plan's Shopify handle | opens `…/charges/{app}/plans/{plan_handle}` — Shopify's approval page with the plan already selected |
| `shopify-picker` | App Pricing, no handle known | opens `…/charges/{app}/pricing_plans`; the copy says "pick Starter there" instead of pretending the choice carried over |
| `charge` | `api` mode, paid plan | `appSubscriptionCreate` with `replacementBehavior: APPLY_IMMEDIATELY` → Shopify's confirmation page, prorated replacement of the old plan |
| `cancel` | `api` mode, Free | `appSubscriptionCancel` — the one direction with no page to visit, so the button says the plan has moved, without navigating anywhere |
| `none` | the admin URL cannot be built | disabled button whose tooltip names the missing `.env` line |

Two deliberate limits. **Approval is never the app's to skip:** a recurring charge needs the merchant
to confirm it on a Shopify page, in either mode, so "inside the app" means the decision and the
proration happen here while the signature happens there — 1.2.3 asks that no support ticket and no
reinstall is needed, not that the iframe never closes. And **a downgrade is never the default choice:**
the strip points at the next tier up, or another paid plan when there is nothing above, and Free only
when it is clicked on purpose — wearing `.btn danger`, with a `confirm()` before it posts.

```
https://admin.shopify.com/store/{store handle}/charges/{app handle}/plans/{plan handle}
```

That second form is **not documented by Shopify** — it is what the plan-selection page links to
internally, and there is an open community request for an official "direct approval link". So the app
never invents a handle: it uses the one Shopify itself sent back as `?plan_handle=` on a previous
plan change (stored per store in `shops.settings → billing.plan_handles`, validated against
`^[A-Za-z0-9_-]{1,64}$` before it goes into a URL path), or `TASKPE_PLAN_STARTER_HANDLE` /
`TASKPE_PLAN_GROWTH_HANDLE` if you paste them in. A wrong handle is a 404 on the page where somebody
had just decided to pay, which is why the plan list stays the fallback for every unknown case. If
Shopify ever changes that path, nothing here breaks: the picker is still what the app opens.

`POST /api/billing/subscribe` is the single door for all of it — `{plan}` from any plan card, the
strip, or a direct API call; it accepts every key in `config/shopify.php → plans` (including `free`),
answers 200 with `redirect_url` when a page owns the decision, and is throttled
`throttle:12,1` because it can spend money. `/billing/callback` then re-reads the store's own
subscription and, while it is there, records the handle for next time.

### "The plan doesn't change — it sends me to the app list"

Reported as a dead button, and it usually is not the button. `…/charges/{app handle}/pricing_plans` is
built from one string, and when that string is not the handle the admin routes on, **Shopify answers by
opening its Apps list** — no 404, no message, nothing a merchant can act on. Three causes, in the order
they are actually found:

| Cause | How to tell | What the app does about it |
|---|---|---|
| `SHOPIFY_APP_HANDLE` is a typo, or is the app *title*, or the API key | `taskpe:plans --links` shows `handle source: env (not confirmed with Shopify)` | The app now asks Shopify for its own handle (`currentAppInstallation.app.handle`), caches it per store in `shops.settings → billing.app_handle_check`, and **prefers it** over the `.env` value; a disagreement is logged and printed |
| The store slug in the URL does not match the admin's (`/store/{slug}`) | `--links` prints the slug; `myshopify` style fixes it | `SHOPIFY_PLANS_URL_STYLE=myshopify` builds `https://{shop}.myshopify.com/admin/charges/{app}/pricing_plans` — the same page, addressed through the domain, which cannot be wrong about the store |
| The link left the iframe without the admin context | the URL in the address bar has no `host` | `withShopifyContext()` appends `host` (from the boot payload, not `location.search`, because the SPA rewrites it) and `shop` to every admin URL it opens |

```bash
php artisan taskpe:plans --links              # per store: slug, handle, where it came from, the URL
php artisan taskpe:plans --links --verify     # …after asking Shopify for its own handle
```

Self-healing, not self-advertising: opening the Plan tab on a store whose link is unconfirmed makes one
`POST /api/billing/verify-handle` (throttled 6/min, once per page load), and if the answer changes the
link the board is re-read so the buttons are rebuilt from the verified handle. A store with a good link
pays nothing for this, and a store whose admin call fails keeps using the config value — an unverified
link is still better than no link.

### The other "the plan did not change": a name this app cannot match

After approval the app maps Shopify's subscription back to one of its own plans. It does that by the
**plan handle Shopify sent on the redirect** (which is also stored, so every later change matches
exactly), then by the plan **name** containing a name from `config/shopify.php → plans`. If neither
matches, the store is mapped to the cheapest paid plan and that decision is *said out loud*: a
`Log::warning` naming the Shopify plan name, `'mapped_by' => 'fallback'` on the shop's billing setting,
and a row in `php artisan taskpe:plans`:

    Plan mapping — what Shopify calls the subscription, and how this app matched it
    | store            | Shopify plan name | matched to | by       | action                                                |
    | demo.myshopify…  | Scale             | starter    | fallback | rename the plan in Partner Dashboard, or set TASKPE_PLAN_*_HANDLE |

So "I paid and the board still shows Starter limits" has an answer that is not a support ticket: rename
the Dashboard plan so it contains `Growth`, or set `TASKPE_PLAN_GROWTH_HANDLE`, and Check again re-maps
it. Silent fallback is the failure mode here — Shopify bills the right amount while the app unlocks the
wrong features, and only the mapping column shows the disagreement.

### Prices on the Plan tab, and the drift check that keeps them honest

The day the buttons worked, the next question arrived: *show the price*. Stripping `prices` out of
the payload had been the fix for a different complaint — a hardcoded ₹499 printed beside a $5.99 plan,
with the app implying it knew the merchant's bill — and the cure left the cards reading "Set at
Shopify", which is honest and useless in the same sentence. Both requirements fit, because they were
never about the same number:

| Number on the page | Where it comes from | What it may be described as |
|---|---|---|
| the amount on the **plan the store is on** | `currentAppInstallation` → `lineItems.plan.pricingDetails … AppRecurringPricing.price` (stored on the shop's `billing` setting at sync) | the invoice. "Billed by Shopify every 30 days, renews <date>" |
| the amount on every **other** plan | `config/shopify.php → plans.<key>.prices`, resolved to the store's billing currency | a **list price**, with the line "List price. Shopify's plan page shows the exact amount for your store." |
| a **missing entry** for the store's currency | the USD row, with `exact: false` | "List price, in US dollars — Shopify converts at its own rate for a store billed in INR." |
| `Free` | only when the plan has no `prices` at all (`free_plan: true`) | "No charge, and no card needed." A paid plan with no price reads "See Shopify", never "Free" |

The payload is `BillingService::planCatalog()` (`GET /api/board` → `plans.<key>`: `name`,
`trial_days`, `list`, `code`, `exact`, `free_plan`, plus `billed` and `drift` on the current plan).
It is sent in **both** billing modes now — `BoardController` no longer unsets anything — and the card
never promotes a list price into a bill, because the two arrive as different fields with different
labels rather than one `prices` blob the renderer has to guess about.

**Drift.** When Shopify's read-back for the current plan and `config` disagree by more than a cent,
`drift` is set and the card says so in the open: which two numbers disagree, that the invoice follows
Shopify, and where to change each one. Silence here is the failure mode — a merchant who spots a price
on your page that their invoice contradicts stops believing the rest of the page.

```bash
php artisan taskpe:plans            # the config table, then every store: list price vs Shopify's
php artisan taskpe:plans --live     # re-read each subscription first (one Admin API call per store)
php artisan taskpe:plans house-of-indha.myshopify.com
```

Exit code is non-zero while any store is being shown a stale price, so it can gate a deploy. It also
warns when `SHOPIFY_APP_HANDLE` is missing in `shopify` mode: the Plan tab can then print a price but
cannot link to the page that changes it, which is the 1.2.3 complaint wearing a different hat.

**After changing either side** — `Partner Dashboard → App pricing` or `config/shopify.php` — run
`php artisan config:clear && php artisan cache:clear` and `taskpe:plans --live`.

**Files this change touches** — unlike a copy-only fix, it is not just the two built assets:
`app/Services/BillingService.php`, `app/Http/Controllers/Api/BoardController.php`,
`app/Console/Commands/PlansCommand.php`, `app/Models/Shop.php`, `config/shopify.php`,
`.env.example`, `routes/api.php`, `app/Http/Controllers/Api/BillingApiController.php`,
`app/Http/Controllers/BillingController.php`, `public/js/app.js`, `public/css/app.css`.
No migration, and nothing you must set — `TASKPE_PLAN_STARTER_HANDLE` and `TASKPE_PLAN_GROWTH_HANDLE` are
optional (they pre-seed plan handles the app otherwise learns from Shopify's own return redirect), and the
extension does not need re-uploading. In `shopify` mode the
config is display copy for the plans a store is not on, and display copy that contradicts the invoice
is worse than none.

### Shopify answers "There's no page at this address"

This is the other half of the failure, and the half a fixed handle cannot cure. `.../charges/{app}/pricing_plans`
is not a page Shopify makes for every app: it exists only when the Partner Dashboard has that app on the
**Shopify App Pricing** model **with at least one plan created**. So when the handle is right — the Apps-list
bounce is gone — and Shopify still answers "There's no page at this address", nothing in this repository is
broken: the app is linking to a page the Dashboard never undertook to make. `php artisan taskpe:plans` prints
this diagnosis with the two checks below, because the app cannot see either fact through the Admin API.

1. **The pricing model.** If the app is on "Set up your own pricing" in the Dashboard, there is no
   Shopify-hosted plan page at all, and the app must not be calling the Billing API either —
   `SHOPIFY_BILLING_MODE` then belongs set to `api` (the app creates and switches the charge itself, in the
   store's own billing currency, which is also the better answer for a non-US launch). Leaving it at `shopify`
   produces precisely this 404.
2. **The plans.** Create Free, Starter and Growth — handle, price per currency, trial days, and the **Redirect
   URL / welcome URL set to `/billing/callback`**, without which a merchant who accepts a plan lands back in the
   app with Shopify never having told it the plan is active. Publish with at least one plan; a plan-less
   listing cannot be approved.

Once the page exists nothing in the app changes: the same link opens, the click returns through
`/billing/callback`, and `currentAppInstallation` confirms it. And because a plan's approval deep link
`.../plans/{handle}` is now filled from `AppRecurringPricing.planHandle` on that plan's own subscription, a
store that has switched once goes straight to that plan's page from then on with no `TASKPE_PLAN_*_HANDLE` set —
the field is an override and a pre-fill, not a requirement.

**The flag that silently stops revenue** is `SHOPIFY_BILLING_TEST=true`, and it matters only in `api` mode: a
charge created with `test: true` is a working subscription on a demo store and a fiction on a paying one. It is
no longer a default in any sense — with that line absent, `BillingService::isDevMode()` decides per store from
Shopify's own `shop.plan.partnerDevelopment` (one cached GraphQL call), so a development store can still
rehearse a plan change while a paying merchant is always billed for real. `php artisan taskpe:plans` prints the
mode, whether charges are forced to test, and what follows from it, because from the merchant's side of the
screen a store on test charges looks exactly like a billed one.

## 18. Two 500s from that deploy: an undefined helper, and `throttle:` asking about a user

Reported straight off the running app, inside the embedded page:

    What failed: Call to undefined function App\Http\Controllers\Api\array_except()
    Endpoint /api/board   HTTP status 500

and, under the staff sign-in form ("Your WhatsApp number"):

    Auth guard [] is not defined.

The first was mine, from the billing change; the second had been sitting there for
much longer and only surfaced because the whole app was being reopened to look at the
first one. Both are the same *class* of failure, which is worth naming: **nothing in
this pipeline executes PHP.** The sandbox has no PHP binary, there is no PHP test
suite, and the SPA probe that covers the other half of the change runs `public/js/app.js`
against a faked `/api/board` payload. A typo in a Blade file, a config value or a
payload builder therefore ships until a merchant pays for it in a 500.

### 1. `array_except()` — not a Laravel helper, never was

`BoardController` stripped the price table with `array_except($p, 'prices')`. The old
`array_get()` / `array_except()`-style global helpers were removed from Laravel years
ago (`Arr::except()` is the function), so the call resolved as "a function in
`App\Http\Controllers\Api`", found nothing, and threw — on the one endpoint every
screen of the app boots from. Fixed by dropping the helper and the `Arr` import
altogether, because plain PHP cannot be missing:

    $plans = collect(config('shopify.plans'))->map(function ($p) use ($managed) {
        if ($managed) {
            unset($p['prices']);
        }

        return $p;
    })->all();

That is the shape it had that day, kept here because the mistake is the point. It is no longer
what the endpoint does: hiding the price table turned into its own problem, and the builder now
calls `BillingService::planCatalog()`, which sends labelled prices in both modes (§ 17). Anyone
reading this to decide what `plans` contains should read the code, not the snippet.

Two habits that keep this class away: prefer a language construct or a repo-proven
helper in payload builders (`collect()`, `config()`, `now()`, `abort_if()` appear
hundreds of times here, so they exist), and lint before uploading:

    find app config -name '*.php' -newermt '-2 hours' -exec php -l {} \;

### 2. `Auth guard [] is not defined.` — a framework question, not an app one

`config/auth.php` ships `'guards' => []` **on purpose** (TaskPe authenticates with a
Shopify session token and a signed staff cookie; there is no users table, and an empty
guard list makes a stray `auth()` call fail loudly instead of half-working). That is a
good decision with a sharp edge nobody noticed: `Illuminate\Routing\Middleware\ThrottleRequests`
calls `$request->user()` **first**, before it falls back to the IP, to decide whose
quota to spend. `Request::user()` asks the auth manager for the default guard; the
default guard is `null`; resolving `null` throws.

So any route carrying `throttle:` was dead for POST requests: `/staff/login` and
`/staff/verify` (staff WhatsApp sign-in) and the courier NDR intake endpoint. The
merchant's screenshot is the staff form's own error slot printing that exception
message — it looked like a WhatsApp/auth configuration problem and was a middleware
ordering accident.

Fix: `app/Http/Middleware/NoLaravelUser.php`, prepended to the `web` and `api` groups
in `bootstrap/app.php`, sets the request's user resolver to `fn (?string $guard = null) => null`
— the honest answer for this app. Rate limiting is unchanged (with no user it keys by
route domain + IP, as it already did for every unauthenticated request), and an explicit
`auth()->guard('web')` call *still* fails loudly. If a real guard is ever added, delete
that middleware rather than keeping both.

### Also changed while here

`BillingService::shopifyManaged()` now reads "is this **not** `api`" instead of "is this
`shopify`". A stale `bootstrap/cache/config.php` (or a typo in `SHOPIFY_BILLING_MODE`)
then leaves the money with Shopify — the safe direction — instead of quietly bringing
back the in-app price table that caused § 17 in the first place.

### Verify after deploying

1. `php artisan config:clear && php artisan queue:restart` — both bugs were config/middleware
   wiring, so a cached config hides either fix.
2. Open the app once inside Shopify admin, then `tail -n 50 storage/logs/laravel.log`: no
   `Call to undefined function`, no `Auth guard`.
3. Staff sign-in from a private browser window: `/staff` → type the number → **Send sign-in
   code** must give either the 6-digit field or one plain sentence (alerts switched off for the
   store, number not on the team). Never an exception message. After five tries in a minute expect
   `429` — that is the throttle doing its job, which is the proof it no longer throws.
4. `curl -i https://<app-host>/up` → 200, then `curl -i -H 'Accept: application/json'
   https://<app-host>/api/board` → **401** JSON (unauthenticated but *rendered*, i.e. the
   payload builder compiled). The real board check is step 2.

### Deploy

`app/Http/Controllers/Api/BoardController.php` (the 500 — if you want the board back in
two minutes, upload this one file and run `php artisan config:clear`),
`app/Http/Middleware/NoLaravelUser.php` (new), `bootstrap/app.php`, `config/auth.php`
(comment only), `app/Services/BillingService.php`. No migration, no extension redeploy.

## 19. `Non-expiring access tokens are no longer accepted for the Admin API`

The message arrived as a GraphQL error while a merchant was linking an order to a task, and the
screen above it said *"Missing API permission. Re-install the app or check scopes."* — which was
wrong twice over: nothing about the scopes had changed, and reinstalling was the one action that
would have cost the merchant nothing but time.

**What Shopify changed.** A public app's offline access token is no longer permanent. It is issued
as a **pair**: an access token that lives about **one hour** (`expires_in: 3600`) and a refresh
token that lives about **90 days** (`refresh_token_expires_in: 7776000`), and every refresh hands
back a **new** refresh token with a new 90 days. The GraphQL Admin API now refuses the old kind
outright for new public apps, and for **every** public app after **1 January 2027** — with a
`shpat_` token that still decrypts, still has the right scopes and still looks fine in the database.
Enforcement is on the GraphQL Admin API only: custom apps and merchant-built apps are exempt, which
is why a private test store can keep working while a real one does not.

**Why the old code broke an hour after it worked.** `AuthController` saved one column
(`shops.access_token`) and never read `expires_in`, so there was nothing to renew *from* — the row
had no refresh token and no expiry. Any app that stores a token as a single string has this bug
waiting, and it is a timing bug rather than a config bug: install at 10:00, fine until 11:00,
`taskpe:send-digests` failing quietly at 09:00 the next morning.

### What the app does now

| Step | Where | Detail |
|---|---|---|
| Ask for the right kind | `ShopifyClient::exchangeCode()` | the code→token POST sends `expiring=1`. Without it Shopify hands back the refused token, so this is the line that matters at install |
| Store all four values | `TokenVault::store()` | access token, refresh token (both `encrypted` casts), and **absolute** expiry dates. Lifetimes are read from the response, never hard-coded, because Shopify's own doc says `expires_in` is the source of truth |
| Renew when needed | `TokenVault::token()`, called by `ShopifyClient::graphql()` | rotates if the token is inside `SHOPIFY_TOKEN_REFRESH_SKEW` (120s) of death. Every Admin API caller in the app inherits it: install, webhook registration, billing read-back, digests, order search, `taskpe:doctor` |
| Migrate stores that already installed | `TokenVault::convertLegacy()` | Shopify's documented direct migration exchange (`grant_type=…token-exchange`, `subject_token` = the old token, `expiring=1`), so **no merchant has to reinstall**. Attempts for a store are spaced an hour apart (`SHOPIFY_TOKEN_MIGRATE_RETRY`) when the token turns out not to be eligible |
| Self-heal one call | `ShopifyClient::graphql()` | if a request is refused for exactly this reason, the vault converts or rotates and the request is replayed **once** |
| Keep quiet stores alive | `routes/console.php` → `taskpe:tokens --rotate` daily | only stores whose refresh token is inside 14 days of expiry are touched, so a merchant who does not open the app for a quarter does not come back to a reconnect wall |
| Say the true thing | `ResourceSearchController::hintFor()` | the merchant-facing line is now "TaskPe's connection to Shopify needs renewing — open TaskPe from Apps in your admin", with no advice to uninstall anything |

Two rules from Shopify's text are load-bearing in the code, and both were easy to get wrong:

* **"Refresh one store at a time."** A per-store `Cache::lock('taskpe:token:<id>')` with the row
  re-read inside it — two workers rotating the same store leave one of them holding a token the
  other already retired. If the lock store is unavailable the work still goes ahead: a rare double
  rotation is survivable (the presented refresh token stays usable until the new one is used), a
  board that refuses to load is not.
* **"Treat that `401` as final."** A dead refresh token is always `401 invalid_request` / *"This
  request requires an active refresh_token"*, whether it was replaced, expired, or the app was
  uninstalled — so the app does not branch on the cause, does not retry, marks the shop for
  reconnect, and tells the merchant to open the app once. A **transient** failure (timeout, 429,
  5xx) is the opposite: it is logged, retried later, and the still-valid hour of access is kept
  rather than thrown away.

`Shop::recentlyGranted()` (120s) keeps the two grants from racing: Shopify warns that acquiring a
token and refreshing one for the same store retire each other, so rotation stands down while an
OAuth callback is landing.

### Reading `php artisan taskpe:tokens`

| `token kind` | meaning | do this |
|---|---|---|
| `expiring` | the pair is stored and renewal works | nothing; the daily pass and the request path handle it |
| `legacy (non-expiring)` | installed before this change | `taskpe:tokens --rotate` — converts in place, no reinstall |
| `expiring, but Shopify rejected it` | the refresh token is dead (90 days with nobody in the app, or a reinstall revoked it) | the merchant opens the app once, from **Apps** in the admin |
| `none — never installed or reinstalled` | no token on the row | install / reconnect |

`--probe` adds a live `{ shop { name } }` per store, so the verdict is Shopify's answer rather than
our reading of a column. `--force` rotates every store regardless of due date — use it after
rotating a client secret, not as routine.

### Deploy

```bash
# 1. code first is safe: TokenVault::hasTokenColumns() degrades to the old single-column
#    behaviour with one log warning instead of throwing on a missing column.
php artisan migrate --force          # adds shops.refresh_token + the three dates
php artisan taskpe:tokens --rotate   # converts every legacy store, in place
php artisan config:clear && php artisan cache:clear
php artisan taskpe:tokens --probe    # Shopify's own verdict, per store
```

The `config:clear` is not decoration: `token_refresh_skew` and `token_migrate_retry` are read from
a cached config, and a stale cache is how a rotation window of 120 seconds silently stays 0.

Nothing here changes scopes, so `read_all_orders` and the protected-customer-data review are
unaffected — a store that could not read old orders before still cannot, and still gets the
link-by-number fallback (§ 16).
