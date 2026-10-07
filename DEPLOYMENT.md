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
