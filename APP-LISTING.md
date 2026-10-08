# TaskPe — Shopify App Store listing + protected-customer-data answers

Paste-ready content for **Partner Dashboard → Apps → TaskPe → Distribution → Manage listing**, and for
**API access requests** (protected customer data, read all orders). Every field below was length-checked
against Shopify's current limits — the report is at the bottom of this file.

**Prefer Word?** `python3 tools/make-docx.py` rebuilds `TaskPe-App-Listing.docx` from this file: the same
content laid out as headings, tables and grey copy-verbatim boxes, plus a final “paste sheet” appendix that
repeats every field you have to type, in form order, with its character count.

Sources: [Best practices for apps in the Shopify App Store](https://shopify.dev/docs/apps/launch/shopify-app-store/best-practices)
(name 30, introduction 100, details 500, features 80, screenshots 1600×900 + alt text, icon 1200×1200,
integrations ≤6, search terms ≤5, install eligibility, review instructions),
[App Store requirements 4.1–4.5](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)
(including 4.4.4 “images must show the app’s UI” and 4.4.5 “each image must be unique”, effective 26 Mar 2026),
and [Work with protected customer data](https://shopify.dev/docs/apps/launch/protected-customer-data).

---

## 1. Choose a primary listing language

**Answer: English.**

| Field | What to pick | Why |
| --- | --- | --- |
| Primary listing language | **English** | An English listing set as primary is **automatically translated** into Brazilian Portuguese, Danish, Dutch, French, German, Simplified Chinese and Spanish/Swedish. It covers the card subtitle, introduction, details, features, pricing, search terms **and image alt text** — which is why the alt texts below are written in plain descriptive sentences with no wordplay. |
| Languages your app's UI supports | **English only** | Shopify asks you to list *all* languages the app UI supports. `config/app.php` pins `locale = en`, there is no `resources/lang` folder and no translator in the SPA, so claiming Hindi or any other UI language would be a false statement in the listing. |
| Add a translated listing | Leave it for now | A custom listing **overwrites and disables** automatic translation for that language (deleting it resumes automation). Add one only when the UI itself is translated — then Hindi (`hi`) is the useful first one for this audience, because `hi` is *not* in the auto-translated set. |

## 2. Listing copy

| Form field | Limit | Value |
| --- | --- | --- |
| App name | 30 | `TaskPe` (alt: `TaskPe Order Tasks`) |
| URL handle / slug | auto | from the name → `taskpe`. It must match `shopify.app.toml` (`name = "TaskPe"`, `handle = "taskpe"`): Shopify wants the same distinctive name everywhere the merchant sees it, and a name must not start with a platform name. Ours is brand-led ✓ |

Copy these two verbatim — they are the fields with the tightest limits:

```text
APP CARD SUBTITLE / INTRODUCTION (100 max):
COD confirmations, NDR rescues and remittance checks, on one task board inside the Shopify admin.
```
```text
APP DETAILS (500 max):
TaskPe turns the follow-ups that decide cash-on-delivery revenue into tasks your team closes. One-click templates create the checklist for an order — verify the payment, call the buyer, chase the courier, reconcile the remittance — with assignee, due date and priority. Tasks link to the Shopify order itself. Staff without a Shopify login use the same board on a phone, signing in with a WhatsApp code. Nothing touches the storefront. Billing runs through Shopify, approved on Shopify’s own page.
```

**Why this copy passes 4.3 (accurate and truthful):** no superlatives, no “first/only/best”, no
outcome guarantees, no invented statistics. “COD”, “NDR” and “RTO” are the vocabulary of the
audience and they are explained by the sentence they sit in, not stuffed as keywords.


### Feature list (≤80 characters each)

1. One-click checklists for COD confirmation, NDR follow-up and RTO risk checks
2. Tasks linked to the order, so the number and status are never retyped
3. Staff board on a phone, signed in with a WhatsApp code — no Shopify login
4. Reminders and a daily digest to your own WhatsApp Business number
5. Columns, teams and priorities that match how your warehouse already works
6. Activity history on every task: who did what, when, and which order it was about

### Integrations (≤6 — not Shopify itself, and only real integrations)

Whatify, Shiprocket, Delhivery, XpressBees

> Do not add “Shopify” as an integration, and do not list courier apps as more than what they are:
> Shiprocket / Delhivery / XpressBees push NDR events to our intake endpoint; we do not write to them.


### Search terms (≤5, complete words, one idea each)

* `cash on delivery`
* `order follow up`
* `delivery exceptions`
* `task board`
* `whatsapp alerts`

### Categories and tags

| Pick | Value |
| --- | --- |
| Primary category | **Orders and shipping** → subcategory **Orders** (“apps that help merchants keep track of customer orders”) |
| Primary tag | **Orders - Other** — this is an order follow-up workflow, not order tracking for customers, not order editing, not invoices. Picking *Order tracking* would be wrong: that tag is for apps that give order info **to customers**, and we never show anything to a customer. |
| Secondary tag | **Cash on delivery (COD)** under *Selling products → Payment options* (“apps that help merchants collect and verify payment during delivery”) — that is literally the COD-confirmation flow. |
| Structured features | Tick only what exists in the shipped build. Do not tick bulk actions / metafield editing / Functions to widen reach; the QA team checks classification and a mismatch is a re-work loop, not a ranking win. |

### Pricing section

| Item | Value |
| --- | --- |
| Primary billing method | **Recurring charge** (not “Free to install” — we have paid plans) |
| Pricing model in the Dashboard | **Set up your own pricing.** This app creates the charge itself through the Billing API, which is what the Plan tab’s buttons do. Choosing **Shopify App Pricing** instead switches who owns the whole flow: Shopify then refuses the app’s own charges (so plan switching breaks with an error the merchant cannot fix), and its hosted plan page only appears once plans exist there. Pick one road; `php artisan taskpe:plans` prints which one the app thinks it is on. |
| Plans | `Free`, `TaskPe Starter`, `TaskPe Growth`. The app’s cards say `Free` / `Starter` / `Growth` and the charge Shopify shows the merchant is named `TaskPe Starter` (the mutation prefixes the app name), so the listing, the card and the approval page are the same three plans with the same numbers. Mark the Free plan as free → “Free plan available” shows in search; plans display lowest→highest on their own. |
| Plan handle | Nothing to fill in, in this mode. Those handles matter only if the app is priced with Shopify App Pricing instead; the app then learns them from the subscription itself and no `.env` line has to be right. |
| Plan descriptions | Free: “2 team members, 50 open tasks, board and activity timeline, no WhatsApp.” Starter: “5 team members, unlimited tasks, WhatsApp alerts to staff, daily owner digest.” Growth: “Unlimited team members, priority support.” |
| Trial | **7 days**, from `config/shopify.php → plans.*.trial_days`, and passed straight to `appSubscriptionCreate` — so the card, Shopify’s confirmation page and this listing cannot disagree. Shopify *recommends* 14: change the config, regenerate this document, redeploy. |
| Additional charges (per plan) | “WhatsApp messages are billed by your own Whatify account (about ₹0.12 per message), not by TaskPe.” |
| Charges billed outside Shopify billing — link | [YOUR PAGE] explaining the Whatify cost. Requirement 4.2 wants outside charges disclosed with a link, and our app genuinely has one. |
| Pricing page link | [YOUR PRICING PAGE] |
| Prices shown here | **US dollars.** `AppRecurringPricingInput.price` permits exactly one currency code — USD — whatever the store is billed in, so the USD figure below is the amount of the charge and Shopify converts it for a non-US invoice. The app’s Plan tab prints the same number and says so in words; the plan a store is on shows the amount read back from its own subscription. Two places have to agree: `config/shopify.php → plans` and this listing. The table below is generated from that config file — change the code and regenerate rather than retyping here. |

#### The price table exactly as the Plan tab prints it (generated from `config/shopify.php`)

| Plan | Price the app charges (USD) | Trial | What to enter in the Partner Dashboard |
| --- | --- | --- | --- |
| **Free** | Free | none | nothing — Free is the absence of a charge, and the app cancels the current one to get back to it |
| **Starter** | $5.99 per 30 days | 7 days | nothing — the app creates the charge from this number, and Shopify prorates an upgrade |
| **Growth** | $11.99 per 30 days | 7 days | nothing — the app creates the charge from this number, and Shopify prorates an upgrade |

An `INR` entry in `prices` is read only when the app is priced with Shopify App Pricing: in this mode the Dashboard sets a real per-currency price and the app merely displays it. It is **not** a charge this app could create — Shopify would answer “Currency code must be USD” on the page where the merchant had already agreed to pay — so a rupee store is shown the USD figure with the line saying Shopify converts it at its own rate on the invoice. That sentence is the promise; nothing in the app claims a rupee price it cannot bill. `DEPLOYMENT.md` § 17 states what each number on a plan card is allowed to claim.

### Links, support and eligibility

| Field | Value |
| --- | --- |
| Privacy policy (required) | `https://<your-host>/privacy` — this route exists in the app. Shopify also wants it reachable without auth; `/privacy` is outside the embedded-app route group ✓ |
| Terms of service | `https://[HOST]/terms` — the app serves it (`routes/web.php` → `AppController::terms`). Replace the two bracketed placeholders in `resources/views/terms.blade.php` (operating entity, governing law) before that URL goes in the form. |
| Help / docs URL | [YOUR DOCS] — write Shopify-specific steps, per Polaris help-documentation guidance; the in-app Setup guide can be mirrored there |
| Support email / phone | [SUPPORT EMAIL] / [PHONE] — also keep *emergency developer contact* current in the dashboard; Shopify pages that contact from there, not from the listing |
| Demo store URL | [YOUR DEV STORE] — link **directly to the page that shows the app**, e.g. `https://<dev-store>.myshopify.com/admin/apps/taskpe`, and put the click path in the instructions below |
| Sales channels needed | **Online Store** only. The app is admin-side and needs no POS. |
| Storefront impact | **None**: no scripts, no theme files, no storefront API. Say this in the review notes — it removes performance questions entirely. |
| Geography | Recommend **no country restriction**: billing is handled by Shopify in the store's currency, and the board works anywhere. Restrict only the *WhatsApp* wording in your copy (“alerts use Whatify, which serves India”) instead of locking merchants out — a hard restriction also locks you out of useful reviews. |

---

## 3. Media: files, spec and alt text

Everything below is already in the repo, sized to spec:


| Upload slot | File | Size | Status |
| --- | --- | --- | --- |
| App icon | `assets/listing/app-icon-1200.png` | **1200×1200** | ✓ built from the 1600 master (`assets/app-icon.png`). Shopify's spec is exactly 1200×1200, JPEG or PNG, **no text**, no Shopify marks. The art is a clipboard + tick with a ₹ coin; a currency symbol inside the pictogram is not “text”, but if you ever list outside India-first markets, ship a coin-free variant. |
| Feature media (static image, since there is no video yet) | `assets/listing/feature-1600x900.png` | 1600×900 | ✓ this is the real board (`01-board.png`), which is exactly what 4.4.4 asks for. `assets/branding/feature-banner.png` is a designed graphic — keep it for social, not for the listing. |
| Screenshots 1–6 (desktop) | `assets/screenshots/01-board.png` … `06-add-task.png` | each **1600×900** | ✓ real captures of the running app from `php artisan taskpe:demo-store`; no browser chrome, no pricing, no reviews, no PII, each image shows a different screen (4.4.5). First three become the preview strip, so order matters: board → templates → create-from-template. |
| Mobile screenshot (optional slot) | `assets/listing/screenshot-mobile-900x1600.png` | **900×1600** | ✓ padded from the 780×1688 phone capture so it meets the spec without cropping the UI. |
| Video (optional, 2–3 min) | — | — | Not recorded. Promo-style, ≤25% screencast, English audio or subtitles. Worth doing: it is the strongest field in 4.5. |

**Before submitting, re-capture from a live store.** These were taken from the demo seeder, which is
perfectly acceptable for review (and is what we tell the reviewer below), but Shopify's image rules are about
*your app's UI* — a capture from a real install removes any argument. Two improvements to make while you're
there: fill the **In Progress** column and add a 6th column so the wide screen doesn't sit empty in shot 1.

### Alt text — one per image, two lengths

Use the ≤100-character version where the field allows it; some listing fields cap lower, so the ≤64
variant is provided. Descriptive, no keyword lists, no “screenshot of”.


| Image | Alt text (≤100) | Short variant (≤64) |
| --- | --- | --- |
| `01-board.png` | Order follow-up board with To Do, Needs Attention, In Progress and Done columns and task cards | Task board with columns and COD follow-up cards |
| `02-template-pack.png` | One-click task templates for COD confirmation, NDR follow-up and RTO risk checks | Pick a ready-made checklist template |
| `03-template-create.png` | Creating a COD confirmation task from a template, with its checklist and order search | Create a task from a template, linked to an order |
| `04-task-checklist.png` | Open task with a tickable checklist, assignee, priority, column and due date | Task detail with checklist and assignee |
| `05-link-object.png` | Search and link a task to an order, draft order, product, customer or blog post | Link any Shopify order to a task |
| `06-add-task.png` | Quick add composer open inside a board column with Add and Cancel | Add a task inside a board column |
| `screenshot-mobile-900x1600.png` | The same team board on a phone, signed in with a WhatsApp code | Staff task board on a phone |
| `feature-1600x900.png` | Board for cash-on-delivery stores: order follow-ups, NDR rescue, remittance checks | TaskPe order follow-up board |

---

## 4. API access requests → Protected customer data

Path: **Partner Dashboard → Apps → TaskPe → API access requests → Protected customer data access → Request access**.
You must select a distribution method (Shopify App Store) before the request is offered.


| Step | Tick / type |
| --- | --- |
| 4. Select **Protected customer data**, provide reasons | **Yes — request Level 1** (order/draft-order data, no name/email/phone/address). Paste **Reason A** below. |
| 5. Select protected customer **fields** | **Decide first — see the fork below.** Default recommendation: tick **none** of Name / Address / Email / Phone. |
| 6. **Data protection details** | Answer from section 5 below — every requirement is already met in code, four items need your hosting facts. |
| 7. Submit for review | With the app submission. |

### The one decision to make before you paste

The order search is **Level 1**: `id name createdAt displayFinancialStatus displayFulfillmentStatus totalPriceSet`
— no identifying field. But the picker's **customer tab** reads `Customer.displayName`, and `displayName` is
derived from `firstName`/`lastName`, i.e. a *name* — that is a **Level 2** field with its own approval and the
level-2 requirements on top.


| Option | What to submit | Cost |
| --- | --- | --- |
| **A — Level 1 only (recommended)** | Request protected customer data; tick **no** fields; scopes `read_orders, read_products, read_content` | Four one-line edits, already mapped out: the tab list in `public/js/app.js` (`types`), the `Rule::in([...])` list and the `match` arm in `ResourceSearchController`, and `read_customers` out of `SHOPIFY_SCOPES` + `shopify.app.toml`. Fastest review, and the strongest possible answer to “minimum data required” — nothing in the workflow needs a customer record, because tasks link to *orders*. |
| **B — keep the customer tab** | Request protected customer data **plus the Name field**, with Reason B below, and complete the Level 2 items in section 5 | Approval takes longer and can pull a data protection review (they focus on install count, record volume, approved fields and retention). If rejected, you must remove the feature anyway. |

### Reason A — paste into the “Protected customer data” reason box


Short version (use if the box caps length tightly; ~540 characters):

```text
TaskPe is an internal follow-up board. It reads orders and draft orders (id, name, created date, financial and fulfilment status, total) only so a staff member can find the order a task is about and link the task to it. We store the order id and the display label on that task row and nothing else: no email, phone, address, no marketing, no profiling, no automated decisions, no sharing or sale of data, no customer-facing surface. Everything is deleted on uninstall and on the GDPR webhooks.
```

Long version (preferred — it answers the four questions a reviewer asks: what, why, where, when deleted):

```text
WHAT WE READ. Orders and draft orders through the Admin GraphQL API, selecting only: id, name, createdAt, displayFinancialStatus, displayFulfillmentStatus, totalPriceSet, status. Products (id, title, status, featured image) and blog articles (id, title) for the same link picker. Nothing else from the Customer or Order objects: we never query or store email, phone, billing or shipping address, and we have not requested those protected fields.

WHY. TaskPe is a follow-up board for cash-on-delivery stores. A task is about one order - "confirm this COD order", "rescue this NDR", "reconcile this remittance" - so the picker searches the order by the number printed on the courier slip or the pack, and the card shows that order's status while the team works. Without it, staff copy order numbers between two screens and chase the wrong order.

WHAT WE STORE. Per store, on our own server: the order id (as a GraphQL GID) and the label text shown on the card, attached to the task row. No line items, no customer records, no documents, no analytics warehouse. We do not write to orders, fulfilments or customers.

WHO SEES IT. Only staff of that store, in the embedded admin page or the phone portal, under the store's own permissions. No public URL, no customer-facing page, no cross-store view.

WEBHOOKS. orders/create is subscribed only when the merchant switches on the optional COD automation; we read id, name and payment_gateway_names from it to decide whether the order is COD, and store just the order id and number on the task we create. Plus app/uninstalled and the mandatory GDPR topics.

RETENTION AND DELETION. A scheduled job prunes WhatsApp delivery logs after 90 days and the webhook ledger after 7 days. app/uninstalled revokes the access token and reverts the store to Free; shop/redact hard-deletes the tenant row and cascades every task, activity entry and order link. customers/data_request and customers/redact reply that no customer PII is held, which is true by design.

SECURITY. HTTPS only (generated URLs forced to https, HMAC-verified webhooks, verified App Bridge session tokens); access tokens encrypted at rest with the app's APP_KEY; database disk encryption at the hosting provider; least-privilege scopes only.
```

### Reason B — only if you keep the customer tab (Name field)

```text
We read Customer.displayName for one screen: the resource picker, where a team member searching for a customer conversation needs to tell two similar order numbers apart. The value is stored only as the label text on that task row, for that store. We do not read email, phone, address or marketing consent fields, we never contact customers, and we do not use it for profiling or automated decisions. If this field is not approved we will remove the customer tab from the picker rather than work around the restriction - the board keeps working for orders, which is what the app is for.
```

### Scope-by-scope justification (same facts, per scope — some forms ask for one reason per scope)

| Scope | Reason |
| --- | --- |
| `read_orders` | Find and link the order a follow-up task is about: id, name, created date, financial/fulfilment status, total. Never writes, never line items, never contact fields. |
| `read_products` | The picker's product tab: id, title, status, featured image — so a “photograph this SKU” task carries the right product. |
| `read_content` | The picker's blog-article tab: id and title only, for content follow-up tasks. |
| `read_customers` | **Only** the customer tab label (`displayName`). If you take option A, delete this scope from `SHOPIFY_SCOPES` and `shopify.app.toml`. |
| `write_*` | **None requested.** The app writes nothing to the store: no metafields, no tags, no order edits. Say this out loud in the submission — it answers a whole category of objections. |

## 5. Data protection details — answer per requirement

The dashboard checklist mirrors the documented requirements. Each answer below is what the code does
today, with the file to point at if asked. `[CONFIRM]` = only you can answer that from your hosting.


**L1.1 Process only the minimum personal data required**  
Every merchant-data read in the app lives in one file, `app/Http/Controllers/Api/ResourceSearchController.php`, and each query selects its minimum: orders and draft orders → id, name, createdAt, displayFinancialStatus / displayFulfillmentStatus, totalPriceSet; products → id, title, status, featured image; blog articles → id, title; customers → id, displayName, numberOfOrders. Nothing else is read anywhere, and no field is copied into our database beyond the id and the label shown on the task card. Dropping the customer tab (option A above) removes `read_customers` entirely.

**L1.2 Inform merchants what data we process and why**  
Stated in the privacy policy linked from the listing, in the in-app Setup guide, and in plain words wherever a feature reads orders - the order picker explains that it looks the order up so the task can carry its number and status, and says which orders it is allowed to see. [CONFIRM] the same sentence appears on the in-app `/privacy` page, which is what merchants and reviewers will actually read.

**L1.3 Limit processing to the stated purposes**  
There is no secondary use: no marketing, no enrichment, no analytics product, no data export endpoint. The only writes are task rows in our own database. No feature reads order data on a schedule; reads happen when a person searches.

**L1.4 Respect customer consent decisions**  
We never contact Shopify customers. The app messages the store's own staff numbers (verified by OTP), and only staff the merchant added. Consent fields are not read, so nothing to apply.

**L1.5 Respect opt-out of data sharing / sale**  
We do not sell or share personal data with anyone, so there is nothing to opt out of, and we send nothing to Shopify customers. WhatsApp messages go to the store's own staff numbers (each verified by an OTP the staff member completed) through the merchant's own Whatify account, and only for alerts the merchant switched on. A message carries the task title and, for order tasks, the order number - never an email, phone number or address of a customer.

**L1.6 No automated decision-making with legal or significant effects**  
The app makes no decisions about customers. Tasks can be auto-created from the orders/create webhook, but only when the merchant enables that option, and the only effect is a to-do on the merchant's own board. No scoring, no profiling, no blocking, no pricing change. Nothing is denied to a customer because of data we hold.

**L1.7 Privacy / data protection agreement available to all merchants**  
Published at [YOUR PRIVACY POLICY URL] and linked from the listing; a DPA on request at [SUPPORT EMAIL]. Same document for every merchant, every country.

**L1.8 Retention periods applied**  
Scheduled `taskpe:prune`: WhatsApp delivery logs 90 days, webhook ledger rows 7 days. Task rows (which carry an order id and a label) live until the merchant deletes the task or the store is redacted. The API credentials are dropped on uninstall - access token, refresh token and the merchant's messaging key - and `shop/redact` deletes the store's whole row, so no historical backlog survives uninstall.

**L1.9 Encrypted at rest and in transit**  
HTTPS forced for every generated URL (OAuth and webhook callbacks), webhooks verified by HMAC, staff portal cookies httpOnly + SameSite=Lax. Shopify credentials are the **expiring offline kind Shopify now requires of public apps**: the access token lives about an hour and is renewed from a refresh token, both encrypted in the database with APP_KEY and neither ever sent to the browser or written to a log (a SHA-256 fingerprint is what appears there), so a leaked row stops working on its own. Disk-level encryption at the host [CONFIRM the provider and enable if not already], backups encrypted at the provider [CONFIRM].

**L2 Encrypt backups**  
[CONFIRM] - if the host's snapshots are not encrypted, either enable provider-side encryption or state that no backups contain customer data because no customer data is stored beyond an order id and a label.

**L2 Test and production data separate**  
Separate app (client ID/secret), separate database and a `config('app.env')`-gated demo seeder (`php artisan taskpe:demo-store`) that writes only fabricated orders to a development store. Production data is never copied down.

**L2 Data loss prevention strategy**  
Least-privilege scopes; per-tenant queries always filtered by shop id so one store can never read another; no bulk export endpoint; no order data in logs (we log order ids at most); no long-lived API credential to exfiltrate (expiring access token + rotating refresh token, both encrypted, `app/Services/TokenVault.php`); access to the server is key-only [CONFIRM SSO/MFA on the host panel]; deploy access limited to named people.

**L2 Limit staff access to protected customer data**  
The app has no admin console holding customer data - our staff see the database only under break-glass conditions, and there is nothing there to browse. On the merchant side, staff portal users see only their own store's tasks, and the link opens the order inside Shopify, under their own Shopify permissions.

**L2 Strong passwords for staff accounts**  
There are no passwords anywhere in TaskPe. Merchants authenticate through Shopify (session token, verified server-side); portal users authenticate with a 6-digit code delivered to a WhatsApp number the merchant registered, rate-limited to 5 requests/minute for the send and 10/minute for the verify, with codes that expire. Invite links are single-use signed tokens.

**L2 Access log to protected customer data**  
Every order link, task change and portal sign-in is written to the task's activity timeline with the acting user, and the app's own request log records the shop domain, route and status - never a query body or customer field. [CONFIRM] log retention/export on the host.

**L2 Security incident response policy**  
[CONFIRM a one-page policy and put its URL here] Minimum it must state: who is on call, severity scale, Shopify notification path (partner email) within 72 hours of a confirmed breach affecting Shopify data, evidence preservation, merchant notification template, post-incident review.


## 6. `read_all_orders` — “describe your app and why you're applying for access”

Path: **API access requests → Read all orders scope → Request access → Orders page**.


```text
TaskPe is a follow-up board, and the follow-ups are about orders placed before the app was installed: COD orders whose remittance arrives 7-15 days after delivery, NDR and RTO cases re-opened weeks later, returns and refunds chased by phone long after the 60-day window. With only recent orders available, staff search the order number from a courier slip, get no result, and the task cannot be linked or closed - the single job this app exists to do. We search by order number or id and read only id, name, created date, financial and fulfilment status and the total: never email, phone, address or line items. We store the order id and its label on one task row, never write to orders, and delete it with the store's tenant on uninstall or shop/redact.
```

Notes for the same form: keep the app version released with `read_all_orders` in
`shopify.app.toml` → `[access_scopes]` **after** approval, set `SHOPIFY_READ_ALL_ORDERS=true`,
`php artisan config:clear`, then have the store reinstall (the scope only lands on a fresh install).
`php artisan taskpe:doctor <shop>` prints which of those three is still missing — quote it in the
review notes if the request comes back with questions. Until approved, the app says so in the picker
and lets the merchant link the order by hand, so nothing is misrepresented while you wait.


## 7. App review instructions (4.5) — paste as-is

This is **`shopify-test-instructions.txt`, verbatim** — that file is what you paste into the *App review
instructions* box, and it is the source of this section: edit the text file, then run
`python3 tools/listing-copy.py && python3 tools/make-docx.py` and both documents follow. Every step states
what to click **and** what to expect, because a reviewer who has to guess the expected result writes a
question instead of an approval; the bracketed `[ ... ]` bits are the credentials only you can fill.

```text
TASKPE — APP REVIEW INSTRUCTIONS
================================

WHAT THE APP IS
TaskPe is an embedded Shopify admin app: the task board for the follow-ups a cash-on-delivery
(COD) store has to do by hand — confirm a COD order before it ships, rescue a failed delivery
(NDR), reconcile the COD remittance, fix an address. Tasks link to the Shopify order itself.
Staff without a Shopify seat use the same board on a phone. Nothing is added to the storefront:
no scripts, no theme files, no theme app embed, so storefront performance is unchanged.

REVIEW STORE (provided for testing)
  Store:       [DEV STORE].myshopify.com    collaborator/store password: [ ... ]
  Staff portal: TaskPe in admin -> Team tab -> "Portal link" -> copy that personal link. Opening
                it signs the member in, with no Shopify login.
  WhatsApp step: needs a Whatify key connected in Settings (a test key is fine); skip step 8 if
                you do not want to exercise delivery.
  The store is pre-seeded so the board is not empty: columns, about ten tasks with checklists and
  orders spread across several months. [adjust this line to whatever you seed] A brand-new install
  instead shows one empty board with "+ Add column" — that is the intended empty state, not a bug.

1. INSTALL AND SCOPES
   Install from the listing and approve the permission screen.
   Expected: the scopes offered are read_orders, read_products, read_content (plus read_customers
   while the picker still has a Customers tab). No write scope, no read_all_orders (see step 4).
   After the redirect the board loads inside the admin from a single /api/board request, and both
   https://[HOST]/privacy and https://[HOST]/terms are readable without a login. Shopify issues the
   expiring offline token that public apps require; the app keeps it renewed from the refresh token
   in the background, so nothing here depends on a permanent credential (php artisan taskpe:tokens
   --probe prints the state per store).

2. CREATE A TASK FROM A TEMPLATE
   Press "t" (or the "COD / NDR task templates" button at the top of the board) -> "COD
   confirmation" -> search an order number that exists in this store -> pick the result -> Create
   task. Expected: a task titled "Confirm COD order #<number>" in the first column, showing 0/6
   checklist steps, linked to that order; the card links straight to the order in admin; the
   activity timeline records who created it.

3. WORK THE TASK
   Open the card, tick two checklist items, change priority and assignee, set a due date, drag the
   card to "In Progress", then drag it to the column marked as the done stage.
   Expected: the card shows 2/6 and the completion is timestamped (the card is struck through in the
   done column); each action adds an activity row naming the person; ticking a checklist item from
   the card does the same.

4. LINKING AN ORDER OLDER THAN THE INSTALL (documented limit — please review this behaviour)
   Search an order created before the app was installed. The picker states that this app may only
   read orders created since the install (read_all_orders is not yet approved) and offers
   "Link an older order by number". Type that order number and save.
   Expected: the task is created with the number as its title, a small "not checked" pill on the
   card, and an activity line saying the order was linked by hand and not verified against Shopify.
   Nothing is invented: no status, total or customer detail is shown for an order we cannot read.
   Pasting a full admin URL like /admin/orders/6123456789 instead links it verified, with no pill.

5. ADMIN EXTENSIONS (three surfaces, all shipped)
   a) Order detail page -> "More actions" -> "Create task". Fill only the title.
      Expected: the task is created and pre-linked to that order with no order read; it appears on
      the board with the order number. Same flow on the draft order, product and customer pages.
   b) Orders list -> select three orders -> "Create TaskPe tasks" -> choose a template.
      Expected: three tasks, one per selected order, each linked.
   c) Order detail page -> the "TaskPe — this order" block. Per Shopify's block rules the merchant
      must add and pin it once (Extensions -> pin); before that the page shows nothing, which is
      expected. Once pinned: the open tasks for that order, tickable inline, plus "Mark done".

6. WEBHOOKS: THE COD AUTO-TASK (off until the merchant turns it on)
   Settings -> "COD / NDR automation" -> tick "Auto-create a confirmation task for every new COD
   order" -> save. Then create a COD order in the admin (Orders -> Create order, payment method
   Cash on Delivery) and save it.
   Expected: within a few seconds a "Confirm COD order #<number>" task is on the board, created by
   the orders/create webhook, with nobody having opened the app. Untick the box and create a second
   COD order: no task appears, because the automation is off by design. Deliveries run through an
   idempotency ledger keyed on the webhook id, so a duplicate retry from Shopify creates one task,
   not two, and the endpoint answers 2xx fast with the work done in a queued job.

7. TEAM AND THE STAFF PORTAL
   Team tab -> add a member (name + phone) -> "Portal link" -> open the link in a private window.
   Expected: the board for that store only; the member can complete and move tasks and add their
   own; there is no Settings tab, no task delete, no billing and no member management. A task whose
   order was linked by hand shows the number as plain text with no admin link, because that member
   has no Shopify access to open it with. Then test phone sign-in on /staff: number -> 6-digit
   WhatsApp code. Expected rate limiting: a 429 once five code requests are made in a minute.

8. WHATSAPP ALERTS (merchant's own provider)
   Settings -> paste a Whatify API key -> enable alerts -> send a test reminder to a member.
   Expected: a delivery-log row with the status and channel, and a message on the member's WhatsApp.
   With no key or a wrong key the panel states what to fix instead of failing silently. TaskPe
   charges nothing here: messages are billed by the merchant's own Whatify account (about INR 0.12
   per message), which is also disclosed in the pricing section of the listing.

9. BILLING AND PLAN CHANGES (the app bills through Shopify's Billing API)
   Plan tab -> the "Switch plan" panel at the top. Click the Starter pill: nothing is charged yet,
   the pill is only chosen, and the sentence under the button says exactly what the next click does.
   Then "Switch to Starter". Expected: the app leaves the iframe and opens Shopify's own approval
   page for that charge - plan name TaskPe Starter, the amount, the 7-day trial, and Shopify's
   proration note if the store is already paying for a plan. The same action is on each plan card
   ("Choose Starter"), so the panel is a shortcut, not the only door. A downgrade and "Move to Free"
   come from the same strip and ask once before they post: a plan change must never need a support
   ticket (Shopify 1.2.3), including the ones that cost money. Cancelling goes back to Free straight
   away, with no ticket and no reinstall.

   The amount is in US dollars and so is the card, because Shopify permits exactly one currency code
   for a charge an app creates (AppRecurringPricingInput.price: USD). A store billed in rupees is
   shown the USD figure with "which Shopify converts into INR at its own rate on the invoice" - that
   sentence is the promise, and the invoice matches it. Once the store is on a plan, its card stops
   showing the app's number and shows the amount read back from the subscription: name, amount,
   currency, interval and renewal date, all Shopify's own figures.

   Approve the plan on Shopify's page and come back. Expected: the tab already reads the live
   subscription (the approval returns through /billing/callback, which re-reads from
   currentAppInstallation rather than trusting URL params), WhatsApp alerts and the daily digest
   unlock, the member and task limits rise, and the charge appears in the store's app charge history
   at Shopify admin -> Settings -> Apps and sales channels -> TaskPe -> Billing. Then the other
   direction: "Change or cancel plan" on the current card -> Growth (Shopify prorates it and retires
   the Starter charge in the same approval), and back down to Free.

   Two things keep that honest without anyone asking. A plan changed anywhere else - cancelled on
   Shopify's own billing screen, approved in a second tab - is picked up on the next board load once
   the cached read is older than 30 minutes, and "Check again" re-reads on demand; the
   app_subscriptions/update webhook covers a change made while nobody had the app open. And if a
   change has not propagated by the time you are back, the tab says "Shopify is applying the
   change..." and re-reads itself after a few seconds rather than reporting a refusal.

   On a development store owned by the same partner organisation the charge is created in test mode,
   so the whole flow completes with no card and no money: the app decides that per store from
   Shopify's own shop.plan.partnerDevelopment, and there is nothing to set. A real store is billed for
   real - SHOPIFY_BILLING_TEST, which forces test charges for every store, is deliberately absent and
   should never appear on a production host.

   The other road, for when this app is priced in the Partner Dashboard instead of here: set
   SHOPIFY_BILLING_MODE=shopify. The app then never creates a charge - Shopify's hosted plan page
   does - and the Plan tab's buttons open admin.shopify.com/store/<store>/charges/<app
   handle>/pricing_plans, or that plan's approval page once Shopify's own plan handle is known (the
   app learns it from the subscription, so no .env line has to be right). Two failures there belong
   to the Dashboard and not to this app, and the app says which one it saw: Shopify's Apps list means
   the app handle in the link is not the one Shopify publishes (the app asks Shopify for it), and
   "There's no page at this address" means App Pricing has no plans yet. If a Dashboard plan's name
   matches no plan in config, the mapping line in `php artisan taskpe:plans` reports it as `fallback`
   rather than quietly unlocking the wrong tier. Both commands to run after changing anything:
   `php artisan taskpe:plans` and, to re-read every subscription first, `php artisan taskpe:plans
   --live`; `--links [--verify]` prints what each button will open.

10. DATA, UNINSTALL AND GDPR
   What a linked task stores: the order GID, the display label and an admin URL. No line items, no
   customer contact fields, no documents, no analytics export.
   Expected on uninstall: the app/uninstalled webhook clears the access token and the saved
   Whatify key and reverts the store to the Free plan; shop/redact hard-deletes the store row and
   cascades its columns, tasks, activity and links. customers/data_request and customers/redact
   are handled (no customer PII is held, and the reply says so). A scheduled job prunes WhatsApp
   delivery logs after 90 days and the webhook ledger after 7 days.

KNOWN LIMITS, NOT BUGS
  - Order search finds only orders created since the install until read_all_orders is approved;
    step 4 is the honest fallback we built for that window.
  - The order-page block stays invisible until the merchant pins it (Shopify's rule for blocks).
  - WhatsApp needs the merchant's own Whatify account; TaskPe is not a BSP and sells no messages.
  - Courier NDR events arrive on a signed token URL (POST /webhooks/ndr/{token}), so they cannot be
    triggered from a browser; the app's own "create from order" flow covers the same task.

SUPPORT
  [SUPPORT EMAIL] · [HELP / DOCS URL] · emergency developer contact is current in the Partner
  Dashboard. Screencast (2-3 min, English): [URL] — install, template to task, checklist, staff
  portal on a phone, billing read-back.
```

**Screenshot → step mapping** (add it under the box if the form has room): shots 2 and 3 = step 2,
shot 4 = step 3, shot 5 = step 2 and step 4, shot 6 = step 2, mobile shot = step 7.
If you take option A in section 4 (no customer tab), re-capture shot 5 so the Customers tab is gone —
a listing image must not show a picker entry the app no longer has.


## 8. Rejection radar (read this before you press submit)


| Risk | Fix |
| --- | --- |
| **1.2.3 — a Plan page with no way to change the plan** (this is what the first review rejected) | A **Switch plan** panel plus a button on every plan card — never a sentence of instructions, and never a support ticket. The app bills through Shopify’s Billing API: picking a plan calls `appSubscriptionCreate`, the merchant approves on Shopify’s own confirmation page, and Shopify prorates an upgrade, sends the invoice and lists the charge in the store’s app charge history. Moving down to Free and cancelling sit on the same control (asked once, because that direction is the one that loses a merchant their features). `SHOPIFY_BILLING_MODE=api` is the shipped default for a reason: Shopify hosts a plan-selection page only for an app priced with **Shopify App Pricing**, and until plans exist there the link `…/charges/{app}/pricing_plans` answers “There’s no page at this address”. So in Partner Dashboard → App pricing the pricing model must be **Set up your own pricing**, and the two must not be mixed: Shopify refuses an app-created charge from an app priced with App Pricing. Prices are shown exactly as they are charged — `config/shopify.php → plans` is the price table and the Plan tab prints that number as the amount Shopify bills every 30 days. The charge currency is **USD and nothing else** (Shopify permits exactly one code for an app-created charge), so a store billed in INR is shown the USD figure with a line saying Shopify converts it at its own rate on its invoice, plus the read-back amount from the store’s own subscription for the plan it is already on. Rehearse on a development store — `shop.plan.partnerDevelopment` is what decides that its charges are test charges, so a real merchant is never handed a fake one: Free → Starter → Growth → Free with no card involved, then screenshot the charge history for the feedback thread. `php artisan taskpe:plans` prints the mode, the price table, each store’s read-back, and whether the two agree. If the app is later priced in the Dashboard instead, the same tab links to Shopify’s page rather than creating a charge: it asks Shopify for its own app handle (`currentAppInstallation.app.handle`, cached per store) because a wrong handle does not 404 but opens the admin’s Apps list, which reads as “the button did nothing”, and it learns each plan’s handle from the subscription to deep-link to that plan’s approval page. `taskpe:plans --links [--verify]` shows both roads. |
| **The Admin API token is the wrong kind for a public app** (the listing looks fine, then every store starts failing one hour after install) | Shopify refuses **non-expiring** offline tokens for GraphQL Admin API calls from public apps - now for new apps, for all of them after **1 January 2027**. The app asks for the expiring pair (`expiring=1` at the token exchange), stores the refresh token and both expiry dates, and renews them itself (`app/Services/TokenVault.php`, `php artisan taskpe:tokens`, DEPLOYMENT.md section 19). Check before submitting: `php artisan taskpe:tokens --probe` must print `expiring` for every store, and the merchant must never see “re-install the app” for what is only a login problem. |
| App icon is 1600px or 1024px | Shopify wants exactly 1200x1200. Use `assets/listing/app-icon-1200.png` (generated from the 1600 master). Corners are pre-rounded in the art; Shopify rounds its own, which is accepted, but if you can supply a full-bleed square that is cleaner. |
| Screenshots contain pricing or a plan card | Deliberately excluded - listing images must not carry pricing (4.4.x). Keep the Plan tab out of screenshots and let the Pricing section hold the numbers. |
| A price in the app disagreeing with the invoice | In `api` mode there is one price table, `config/shopify.php → plans`, and it is the amount of the charge, so nothing can drift from a Dashboard row. What does happen is a store that joined before a price change: its subscription keeps the price it was approved at. That is why the current plan’s card prints Shopify’s read-back figure instead of the config one, why `php artisan taskpe:plans --live` shows both per store, and why an edit takes effect at the next approval. |
| Screenshots look like mockups or show browser chrome | All six are real captures at exactly 1600x900 with no chrome, no overlays, unique content per image (4.4.4/4.4.5 from 26 March 2026). |
| Alt text missing or too long | Every image has alt text in two sizes here: <=100 characters for the form, <=64 for fields that cap lower. Automated translation covers alt text for the eight languages, so write plain descriptive English and no keyword lists. |
| Customer search reads a name field | That is Level 2 (`displayName` is derived from first/last name). Either request the Name field with the reason below, or drop the customer tab + `read_customers` and keep the request at Level 1 - the fastest approval, and the more defensible answer to 'minimum data required'. |
| `read_all_orders` not yet approved | Order search outside the install window returns nothing. The reason text below is written for that request; the app already explains the limit in-app, so no merchant is misled while the request is open. |
| Trial promised but not delivered | The listing must match what the app actually asks for. In `api` mode the trial comes from `config/shopify.php → plans.*.trial_days` (7 days now) and is passed to `appSubscriptionCreate`, so there is no Dashboard field that can disagree with it. Shopify’s guidance recommends 14 — change the config, regenerate this document, and the cards, the confirmation page and the listing move together. |
| Charges outside Shopify billing hidden | WhatsApp messages are billed by the merchant's own Whatify account. That belongs in the plan's 'additional charges' line with a link to a page that explains it (4.2). |
| Support and legal links | Privacy policy URL is required; add Terms of Service and a support/docs URL. `/privacy` and `/terms` are both served by the app (`AppController::privacy|terms`, public, no login). Two things still have to go into `resources/views/terms.blade.php` before that link is quoted anywhere: the legal entity and the governing law - both are marked with `[` on the page, and the page prints a note telling you to delete it afterwards. |
| Storefront performance | Nothing is injected into the storefront (no scripts, no theme edits), so the Lighthouse delta is zero. Say so in the review instructions; it removes a whole class of questions. |

## 9. Field-length report (generated — regenerate after any copy edit)


This file and the report above are produced by `python3 tools/listing-copy.py`, which **refuses to write**
if any field exceeds its Shopify limit. Edit the copy there, not here, and the limits stay true.

| Field | Length | Limit |
| --- | --- | --- |
| App name (30 max) | 6 | 30 |
| Alt name (30 max) | 18 | 30 |
| App card subtitle (100 max) | 97 | 100 |
| App details (500 max) | 497 | 500 |
| Feature 1 (80 max) | 76 | 80 |
| Feature 2 (80 max) | 69 | 80 |
| Feature 3 (80 max) | 73 | 80 |
| Feature 4 (80 max) | 65 | 80 |
| Feature 5 (80 max) | 73 | 80 |
| Feature 6 (80 max) | 80 | 80 |
| alt 01-board.png (100 max) | 94 | 100 |
| alt-short 01-board.png (64 max) | 47 | 64 |
| alt 02-template-pack.png (100 max) | 80 | 100 |
| alt-short 02-template-pack.png (64 max) | 36 | 64 |
| alt 03-template-create.png (100 max) | 85 | 100 |
| alt-short 03-template-create.png (64 max) | 49 | 64 |
| alt 04-task-checklist.png (100 max) | 76 | 100 |
| alt-short 04-task-checklist.png (64 max) | 39 | 64 |
| alt 05-link-object.png (100 max) | 79 | 100 |
| alt-short 05-link-object.png (64 max) | 32 | 64 |
| alt 06-add-task.png (100 max) | 65 | 100 |
| alt-short 06-add-task.png (64 max) | 32 | 64 |
| alt screenshot-mobile-900x1600.png (100 max) | 62 | 100 |
| alt-short screenshot-mobile-900x1600.png (64 max) | 27 | 64 |
| alt feature-1600x900.png (100 max) | 82 | 100 |
| alt-short feature-1600x900.png (64 max) | 28 | 64 |
| Integrations count (6 max) | 4 | 6 |
| Search terms (5 max) | 5 | 5 |

---

### Still to fill before submitting

1. Both legal pages exist — `https://<host>/privacy` and `https://<host>/terms`. Fill the two placeholders in `resources/views/terms.blade.php` (operating entity, governing law), then link both.
placeholders in `resources/views/terms.blade.php` (operating entity, governing law), then link both.
2. Support email, docs URL, demo store URL, emergency developer contact. `SHOPIFY_APP_HANDLE` in `.env` is only needed if the app is priced with Shopify App Pricing instead (the 1.2.3 row above); in `api` mode the app creates the charge itself and no hosted plan page is involved.
3. Pricing page that explains charges billed outside Shopify (Whatify).
4. Decide the Level 1 / Level 2 fork in section 4, and if Level 1: remove the customer tab + `read_customers`.
5. Optional but high-value: the 2–3 minute feature video, and re-capturing screenshots from a real store.

