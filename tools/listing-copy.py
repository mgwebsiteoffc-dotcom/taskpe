import io
import re

TEST = io.open('shopify-test-instructions.txt', encoding='utf8').read().rstrip('\n')

# The Plan tab prints config/shopify.php's list prices now, so the listing is generated FROM that
# table instead of paraphrasing it: one price edit then moves the app, this document and the
# Dashboard instructions together, and the copy cannot drift away from the product it describes.
CFG = io.open('config/shopify.php', encoding='utf8').read()


def read_plans():
    plans = []

    for key in re.findall(r"^        '([a-z_]+)' => \[$", CFG, re.M):
        block = CFG.split("'" + key + "' => [", 1)[1].split("\n        ],", 1)[0]
        name = re.search(r"'name'\s*=>\s*'([^']+)'", block)
        trial = re.search(r"'trial_days'\s*=>\s*(\d+)", block)
        prices = re.search(r"'prices'\s*=>\s*\[([^\]\n]*)\]", block)

        plans.append({
            'key': key,
            'name': name.group(1) if name else key,
            'trial': int(trial.group(1)) if trial else 0,
            'prices': dict((c, float(a)) for c, a in re.findall(
                r"'([A-Z]{3})' => ([0-9.]+)", prices.group(1) if prices else '')),
        })

    return plans


PLANS = read_plans()
assert PLANS and PLANS[0]['prices'] == {}, 'could not read config/shopify.php -> plans'


def money(amount, code):
    symbol = '₹' if code == 'INR' else ('$' if code == 'USD' else code + ' ')

    return symbol + ('%.0f' % amount if code == 'INR' else '%.2f' % amount)


CHARGE = 'USD'   # Shopify accepts no other currency code for appSubscriptionCreate


def price_text(plan):
    """What the merchant is charged, in the one currency an app-created charge may use."""
    if not plan['prices']:
        return 'Free'

    if CHARGE in plan['prices']:
        return money(plan['prices'][CHARGE], CHARGE) + ' per 30 days'

    return 'no %s price configured \u2014 the app cannot bill this plan' % CHARGE

L = {}
L['name'] = "TaskPe"
L['name_alt'] = "TaskPe Order Tasks"
L['subtitle'] = "COD confirmations, NDR rescues and remittance checks, on one task board inside the Shopify admin."
L['details'] = (
    "TaskPe turns the follow-ups that decide cash-on-delivery revenue into tasks your team closes. One-click "
    "templates create the checklist for an order — verify the payment, call the buyer, chase the courier, "
    "reconcile the remittance — with assignee, due date and priority. Tasks link to the Shopify order itself. "
    "Staff without a Shopify login use the same board on a phone, signing in with a WhatsApp code. Nothing "
    "touches the storefront. Billing runs through Shopify, approved on Shopify\u2019s own page."
)
L['features'] = [
    "One-click checklists for COD confirmation, NDR follow-up and RTO risk checks",
    "Tasks linked to the order, so the number and status are never retyped",
    "Staff board on a phone, signed in with a WhatsApp code — no Shopify login",
    "Reminders and a daily digest to your own WhatsApp Business number",
    "Columns, teams and priorities that match how your warehouse already works",
    "Activity history on every task: who did what, when, and which order it was about",
]
L['integrations'] = ["Whatify", "Shiprocket", "Delhivery", "XpressBees"]
L['search'] = ["cash on delivery", "order follow up", "delivery exceptions", "task board", "whatsapp alerts"]

L['alt'] = {
    "01-board.png": ("Order follow-up board with To Do, Needs Attention, In Progress and Done columns and task cards",
                     "Task board with columns and COD follow-up cards"),
    "02-template-pack.png": ("One-click task templates for COD confirmation, NDR follow-up and RTO risk checks",
                            "Pick a ready-made checklist template"),
    "03-template-create.png": ("Creating a COD confirmation task from a template, with its checklist and order search",
                              "Create a task from a template, linked to an order"),
    "04-task-checklist.png": ("Open task with a tickable checklist, assignee, priority, column and due date",
                             "Task detail with checklist and assignee"),
    "05-link-object.png": ("Search and link a task to an order, draft order, product, customer or blog post",
                          "Link any Shopify order to a task"),
    "06-add-task.png": ("Quick add composer open inside a board column with Add and Cancel",
                       "Add a task inside a board column"),
    "screenshot-mobile-900x1600.png": ("The same team board on a phone, signed in with a WhatsApp code",
                                      "Staff task board on a phone"),
    "feature-1600x900.png": ("Board for cash-on-delivery stores: order follow-ups, NDR rescue, remittance checks",
                            "TaskPe order follow-up board"),
}

L['pcd_short'] = (
    "TaskPe is an internal follow-up board. It reads orders and draft orders (id, name, created date, "
    "financial and fulfilment status, total) only so a staff member can find the order a task is about and "
    "link the task to it. We store the order id and the display label on that task row and nothing else: no "
    "email, phone, address, no marketing, no profiling, no automated decisions, no sharing or sale of data, "
    "no customer-facing surface. Everything is deleted on uninstall and on the GDPR webhooks."
)
L['pcd_long'] = (
    "WHAT WE READ. Orders and draft orders through the Admin GraphQL API, selecting only: id, name, "
    "createdAt, displayFinancialStatus, displayFulfillmentStatus, totalPriceSet, status. Products (id, title, "
    "status, featured image) and blog articles (id, title) for the same link picker. Nothing else from the "
    "Customer or Order objects: we never query or store email, phone, billing or shipping address, and we "
    "have not requested those protected fields.\n\n"
    "WHY. TaskPe is a follow-up board for cash-on-delivery stores. A task is about one order - \"confirm this "
    "COD order\", \"rescue this NDR\", \"reconcile this remittance\" - so the picker searches the order by the "
    "number printed on the courier slip or the pack, and the card shows that order's status while the team "
    "works. Without it, staff copy order numbers between two screens and chase the wrong order.\n\n"
    "WHAT WE STORE. Per store, on our own server: the order id (as a GraphQL GID) and the label text shown on "
    "the card, attached to the task row. No line items, no customer records, no documents, no analytics "
    "warehouse. We do not write to orders, fulfilments or customers.\n\n"
    "WHO SEES IT. Only staff of that store, in the embedded admin page or the phone portal, under the store's "
    "own permissions. No public URL, no customer-facing page, no cross-store view.\n\n"
    "WEBHOOKS. orders/create is subscribed only when the merchant switches on the optional COD automation; we "
    "read id, name and payment_gateway_names from it to decide whether the order is COD, and store just the "
    "order id and number on the task we create. Plus app/uninstalled and the mandatory GDPR topics.\n\n"
    "RETENTION AND DELETION. A scheduled job prunes WhatsApp delivery logs after 90 days and the webhook "
    "ledger after 7 days. app/uninstalled revokes the access token and reverts the store to Free; shop/redact "
    "hard-deletes the tenant row and cascades every task, activity entry and order link. "
    "customers/data_request and customers/redact reply that no customer PII is held, which is true by design.\n\n"
    "SECURITY. HTTPS only (generated URLs forced to https, HMAC-verified webhooks, verified App Bridge session "
    "tokens); access tokens encrypted at rest with the app's APP_KEY; database disk encryption at the hosting "
    "provider; least-privilege scopes only."
)
L['all_orders'] = (
    "TaskPe is a follow-up board, and the follow-ups are about orders placed before the app was installed: "
    "COD orders whose remittance arrives 7-15 days after delivery, NDR and RTO cases re-opened weeks later, "
    "returns and refunds chased by phone long after the 60-day window. With only recent orders available, "
    "staff search the order number from a courier slip, get no result, and the task cannot be linked or "
    "closed - the single job this app exists to do. We search by order number or id and read only id, name, "
    "created date, financial and fulfilment status and the total: never email, phone, address or line items. "
    "We store the order id and its label on one task row, never write to orders, and delete it with the "
    "store's tenant on uninstall or shop/redact."
)
L['name_field'] = (
    "We read Customer.displayName for one screen: the resource picker, where a team member searching for a "
    "customer conversation needs to tell two similar order numbers apart. The value is stored only as the "
    "label text on that task row, for that store. We do not read email, phone, address or marketing consent "
    "fields, we never contact customers, and we do not use it for profiling or automated decisions. If this "
    "field is not approved we will remove the customer tab from the picker rather than work around the "
    "restriction - the board keeps working for orders, which is what the app is for."
)

DP = [
 ("L1.1 Process only the minimum personal data required",
  "Every merchant-data read in the app lives in one file, `app/Http/Controllers/Api/ResourceSearchController.php`, and each query selects its minimum: orders and draft orders → id, name, createdAt, displayFinancialStatus / displayFulfillmentStatus, totalPriceSet; products → id, title, status, featured image; blog articles → id, title; customers → id, displayName, numberOfOrders. Nothing else is read anywhere, and no field is copied into our database beyond the id and the label shown on the task card. Dropping the customer tab (option A above) removes `read_customers` entirely."),
 ("L1.2 Inform merchants what data we process and why",
  "Stated in the privacy policy linked from the listing, in the in-app Setup guide, and in plain words wherever a feature reads orders - the order picker explains that it looks the order up so the task can carry its number and status, and says which orders it is allowed to see. [CONFIRM] the same sentence appears on the in-app `/privacy` page, which is what merchants and reviewers will actually read."),
 ("L1.3 Limit processing to the stated purposes",
  "There is no secondary use: no marketing, no enrichment, no analytics product, no data export endpoint. The only writes are task rows in our own database. No feature reads order data on a schedule; reads happen when a person searches."),
 ("L1.4 Respect customer consent decisions",
  "We never contact Shopify customers. The app messages the store's own staff numbers (verified by OTP), and only staff the merchant added. Consent fields are not read, so nothing to apply."),
 ("L1.5 Respect opt-out of data sharing / sale",
  "We do not sell or share personal data with anyone, so there is nothing to opt out of, and we send nothing to Shopify customers. WhatsApp messages go to the store's own staff numbers (each verified by an OTP the staff member completed) through the merchant's own Whatify account, and only for alerts the merchant switched on. A message carries the task title and, for order tasks, the order number - never an email, phone number or address of a customer."),
 ("L1.6 No automated decision-making with legal or significant effects",
  "The app makes no decisions about customers. Tasks can be auto-created from the orders/create webhook, but only when the merchant enables that option, and the only effect is a to-do on the merchant's own board. No scoring, no profiling, no blocking, no pricing change. Nothing is denied to a customer because of data we hold."),
 ("L1.7 Privacy / data protection agreement available to all merchants",
  "Published at [YOUR PRIVACY POLICY URL] and linked from the listing; a DPA on request at [SUPPORT EMAIL]. Same document for every merchant, every country."),
 ("L1.8 Retention periods applied",
  "Scheduled `taskpe:prune`: WhatsApp delivery logs 90 days, webhook ledger rows 7 days. Task rows (which carry an order id and a label) live until the merchant deletes the task or the store is redacted. The API credentials are dropped on uninstall - access token, refresh token and the merchant's messaging key - and `shop/redact` deletes the store's whole row, so no historical backlog survives uninstall."),
 ("L1.9 Encrypted at rest and in transit",
  "HTTPS forced for every generated URL (OAuth and webhook callbacks), webhooks verified by HMAC, staff portal cookies httpOnly + SameSite=Lax. Shopify credentials are the **expiring offline kind Shopify now requires of public apps**: the access token lives about an hour and is renewed from a refresh token, both encrypted in the database with APP_KEY and neither ever sent to the browser or written to a log (a SHA-256 fingerprint is what appears there), so a leaked row stops working on its own. Disk-level encryption at the host [CONFIRM the provider and enable if not already], backups encrypted at the provider [CONFIRM]."),
 ("L2 Encrypt backups",
  "[CONFIRM] - if the host's snapshots are not encrypted, either enable provider-side encryption or state that no backups contain customer data because no customer data is stored beyond an order id and a label."),
 ("L2 Test and production data separate",
  "Separate app (client ID/secret), separate database and a `config('app.env')`-gated demo seeder (`php artisan taskpe:demo-store`) that writes only fabricated orders to a development store. Production data is never copied down."),
 ("L2 Data loss prevention strategy",
  "Least-privilege scopes; per-tenant queries always filtered by shop id so one store can never read another; no bulk export endpoint; no order data in logs (we log order ids at most); no long-lived API credential to exfiltrate (expiring access token + rotating refresh token, both encrypted, `app/Services/TokenVault.php`); access to the server is key-only [CONFIRM SSO/MFA on the host panel]; deploy access limited to named people."),
 ("L2 Limit staff access to protected customer data",
  "The app has no admin console holding customer data - our staff see the database only under break-glass conditions, and there is nothing there to browse. On the merchant side, staff portal users see only their own store's tasks, and the link opens the order inside Shopify, under their own Shopify permissions."),
 ("L2 Strong passwords for staff accounts",
  "There are no passwords anywhere in TaskPe. Merchants authenticate through Shopify (session token, verified server-side); portal users authenticate with a 6-digit code delivered to a WhatsApp number the merchant registered, rate-limited to 5 requests/minute for the send and 10/minute for the verify, with codes that expire. Invite links are single-use signed tokens."),
 ("L2 Access log to protected customer data",
  "Every order link, task change and portal sign-in is written to the task's activity timeline with the acting user, and the app's own request log records the shop domain, route and status - never a query body or customer field. [CONFIRM] log retention/export on the host."),
 ("L2 Security incident response policy",
  "[CONFIRM a one-page policy and put its URL here] Minimum it must state: who is on call, severity scale, Shopify notification path (partner email) within 72 hours of a confirmed breach affecting Shopify data, evidence preservation, merchant notification template, post-incident review."),
]


RADAR = [
 ("**1.2.3 \u2014 a Plan page with no way to change the plan** (this is what the first review rejected)",
  "A **Switch plan** panel plus a button on every plan card \u2014 never a sentence of instructions, and never a support ticket. The app bills through Shopify\u2019s Billing API: picking a plan calls `appSubscriptionCreate`, the merchant approves on Shopify\u2019s own confirmation page, and Shopify prorates an upgrade, sends the invoice and lists the charge in the store\u2019s app charge history. Moving down to Free and cancelling sit on the same control (asked once, because that direction is the one that loses a merchant their features). `SHOPIFY_BILLING_MODE=api` is the shipped default for a reason: Shopify hosts a plan-selection page only for an app priced with **Shopify App Pricing**, and until plans exist there the link `\u2026/charges/{app}/pricing_plans` answers \u201cThere\u2019s no page at this address\u201d. So in Partner Dashboard \u2192 App pricing the pricing model must be **Set up your own pricing**, and the two must not be mixed: Shopify refuses an app-created charge from an app priced with App Pricing. Prices are shown exactly as they are charged \u2014 `config/shopify.php \u2192 plans` is the price table and the Plan tab prints that number as the amount Shopify bills every 30 days. The charge currency is **USD and nothing else** (Shopify permits exactly one code for an app-created charge), so a store billed in INR is shown the USD figure with a line saying Shopify converts it at its own rate on its invoice, plus the read-back amount from the store\u2019s own subscription for the plan it is already on. Rehearse on a development store \u2014 `shop.plan.partnerDevelopment` is what decides that its charges are test charges, so a real merchant is never handed a fake one: Free \u2192 Starter \u2192 Growth \u2192 Free with no card involved, then screenshot the charge history for the feedback thread. `php artisan taskpe:plans` prints the mode, the price table, each store\u2019s read-back, and whether the two agree. If the app is later priced in the Dashboard instead, the same tab links to Shopify\u2019s page rather than creating a charge: it asks Shopify for its own app handle (`currentAppInstallation.app.handle`, cached per store) because a wrong handle does not 404 but opens the admin\u2019s Apps list, which reads as \u201cthe button did nothing\u201d, and it learns each plan\u2019s handle from the subscription to deep-link to that plan\u2019s approval page. `taskpe:plans --links [--verify]` shows both roads."),

 ("**The Admin API token is the wrong kind for a public app** (the listing looks fine, then every store starts failing one hour after install)",
  "Shopify refuses **non-expiring** offline tokens for GraphQL Admin API calls from public apps - now for new apps, for all of them after **1 January 2027**. The app asks for the expiring pair (`expiring=1` at the token exchange), stores the refresh token and both expiry dates, and renews them itself (`app/Services/TokenVault.php`, `php artisan taskpe:tokens`, DEPLOYMENT.md section 19). Check before submitting: `php artisan taskpe:tokens --probe` must print `expiring` for every store, and the merchant must never see “re-install the app” for what is only a login problem."),
 ("App icon is 1600px or 1024px", "Shopify wants exactly 1200x1200. Use `assets/listing/app-icon-1200.png` (generated from the 1600 master). Corners are pre-rounded in the art; Shopify rounds its own, which is accepted, but if you can supply a full-bleed square that is cleaner."),
 ("Screenshots contain pricing or a plan card", "Deliberately excluded - listing images must not carry pricing (4.4.x). Keep the Plan tab out of screenshots and let the Pricing section hold the numbers."),
 ("A price in the app disagreeing with the invoice", "In `api` mode there is one price table, "
  "`config/shopify.php \u2192 plans`, and it is the amount of the charge, so nothing can drift from a "
  "Dashboard row. What does happen is a store that joined before a price change: its subscription keeps "
  "the price it was approved at. That is why the current plan\u2019s card prints Shopify\u2019s read-back "
  "figure instead of the config one, why `php artisan taskpe:plans --live` shows both per store, and why "
  "an edit takes effect at the next approval."),
 ("Screenshots look like mockups or show browser chrome", "All six are real captures at exactly 1600x900 with no chrome, no overlays, unique content per image (4.4.4/4.4.5 from 26 March 2026)."),
 ("Alt text missing or too long", "Every image has alt text in two sizes here: <=100 characters for the form, <=64 for fields that cap lower. Automated translation covers alt text for the eight languages, so write plain descriptive English and no keyword lists."),
 ("Customer search reads a name field", "That is Level 2 (`displayName` is derived from first/last name). Either request the Name field with the reason below, or drop the customer tab + `read_customers` and keep the request at Level 1 - the fastest approval, and the more defensible answer to 'minimum data required'."),
 ("`read_all_orders` not yet approved", "Order search outside the install window returns nothing. The reason text below is written for that request; the app already explains the limit in-app, so no merchant is misled while the request is open."),
 ("Trial promised but not delivered", "The listing must match what the app actually asks for. In `api` "
  "mode the trial comes from `config/shopify.php \u2192 plans.*.trial_days` (7 days now) and is passed to "
  "`appSubscriptionCreate`, so there is no Dashboard field that can disagree with it. Shopify\u2019s "
  "guidance recommends 14 \u2014 change the config, regenerate this document, and the cards, the "
  "confirmation page and the listing move together."),
 ("Charges outside Shopify billing hidden", "WhatsApp messages are billed by the merchant's own Whatify account. That belongs in the plan's 'additional charges' line with a link to a page that explains it (4.2)."),
 ("Support and legal links", "Privacy policy URL is required; add Terms of Service and a support/docs URL. `/privacy` and `/terms` are both served by the app (`AppController::privacy|terms`, public, no login). Two things still have to go into `resources/views/terms.blade.php` before that link is quoted anywhere: the legal entity and the governing law - both are marked with `[` on the page, and the page prints a note telling you to delete it afterwards."),
 ("Storefront performance", "Nothing is injected into the storefront (no scripts, no theme edits), so the Lighthouse delta is zero. Say so in the review instructions; it removes a whole class of questions."),
]

def fence(text):
    return text

rows = [('App name (30 max)', len(L['name']), 30), ('Alt name (30 max)', len(L['name_alt']), 30),
        ('App card subtitle (100 max)', len(L['subtitle']), 100), ('App details (500 max)', len(L['details']), 500)]
rows += [('Feature %d (80 max)' % i, len(f), 80) for i, f in enumerate(L['features'], 1)]
for fname, (a, b) in L['alt'].items():
    rows += [('alt %s (100 max)' % fname, len(a), 100), ('alt-short %s (64 max)' % fname, len(b), 64)]
rows += [('Integrations count (6 max)', len(L['integrations']), 6), ('Search terms (5 max)', len(L['search']), 5)]
bad = [r for r in rows if r[1] > r[2]]
print('=== field-length report ===')
for label, n, cap in rows:
    print('%-46s %4d / %-4d %s' % (label, n, cap, 'ok' if n <= cap else 'OVER'))
if bad:
    raise SystemExit('TOO LONG: %s' % bad)

out = []
w = out.append

w("# TaskPe — Shopify App Store listing + protected-customer-data answers\n")
w("Paste-ready content for **Partner Dashboard → Apps → TaskPe → Distribution → Manage listing**, and for")
w("**API access requests** (protected customer data, read all orders). Every field below was length-checked")
w("against Shopify's current limits — the report is at the bottom of this file.")
w("")
w("**Prefer Word?** `python3 tools/make-docx.py` rebuilds `TaskPe-App-Listing.docx` from this file: the same")
w("content laid out as headings, tables and grey copy-verbatim boxes, plus a final “paste sheet” appendix that")
w("repeats every field you have to type, in form order, with its character count.")
w("")
w("Sources: [Best practices for apps in the Shopify App Store](https://shopify.dev/docs/apps/launch/shopify-app-store/best-practices)")
w("(name 30, introduction 100, details 500, features 80, screenshots 1600×900 + alt text, icon 1200×1200,")
w("integrations ≤6, search terms ≤5, install eligibility, review instructions),")
w("[App Store requirements 4.1–4.5](https://shopify.dev/docs/apps/launch/shopify-app-store/app-store-requirements)")
w("(including 4.4.4 “images must show the app’s UI” and 4.4.5 “each image must be unique”, effective 26 Mar 2026),")
w("and [Work with protected customer data](https://shopify.dev/docs/apps/launch/protected-customer-data).")
w("")
w("---")
w("")
w("## 1. Choose a primary listing language\n")
w("**Answer: English.**\n")
w("| Field | What to pick | Why |")
w("| --- | --- | --- |")
w("| Primary listing language | **English** | An English listing set as primary is **automatically translated** into Brazilian Portuguese, Danish, Dutch, French, German, Simplified Chinese and Spanish/Swedish. It covers the card subtitle, introduction, details, features, pricing, search terms **and image alt text** — which is why the alt texts below are written in plain descriptive sentences with no wordplay. |")
w("| Languages your app's UI supports | **English only** | Shopify asks you to list *all* languages the app UI supports. `config/app.php` pins `locale = en`, there is no `resources/lang` folder and no translator in the SPA, so claiming Hindi or any other UI language would be a false statement in the listing. |")
w("| Add a translated listing | Leave it for now | A custom listing **overwrites and disables** automatic translation for that language (deleting it resumes automation). Add one only when the UI itself is translated — then Hindi (`hi`) is the useful first one for this audience, because `hi` is *not* in the auto-translated set. |")
w("")
w("## 2. Listing copy\n")
w("| Form field | Limit | Value |")
w("| --- | --- | --- |")
w("| App name | 30 | `" + L['name'] + "` (alt: `" + L['name_alt'] + "`) |")
w("| URL handle / slug | auto | from the name → `taskpe`. It must match `shopify.app.toml` (`name = \"TaskPe\"`, `handle = \"taskpe\"`): Shopify wants the same distinctive name everywhere the merchant sees it, and a name must not start with a platform name. Ours is brand-led ✓ |")
w("")
w("Copy these two verbatim — they are the fields with the tightest limits:\n")
w("```text")
w("APP CARD SUBTITLE / INTRODUCTION (100 max):")
w(L['subtitle'])
w("```")
w("```text")
w("APP DETAILS (500 max):")
w(L['details'])
w("```")
w("")
w("**Why this copy passes 4.3 (accurate and truthful):** no superlatives, no “first/only/best”, no")
w("outcome guarantees, no invented statistics. “COD”, “NDR” and “RTO” are the vocabulary of the")
w("audience and they are explained by the sentence they sit in, not stuffed as keywords.\n")
w("")
w("### Feature list (≤80 characters each)\n")
for i, f in enumerate(L['features'], 1):
    w("%d. %s" % (i, f))
w("")
w("### Integrations (≤6 — not Shopify itself, and only real integrations)\n")
w(", ".join(L['integrations']))
w("")
w("> Do not add “Shopify” as an integration, and do not list courier apps as more than what they are:")
w("> Shiprocket / Delhivery / XpressBees push NDR events to our intake endpoint; we do not write to them.\n")
w("")
w("### Search terms (≤5, complete words, one idea each)\n")
for s in L['search']:
    w("* `%s`" % s)
w("")
w("### Categories and tags\n")
w("| Pick | Value |")
w("| --- | --- |")
w("| Primary category | **Orders and shipping** → subcategory **Orders** (“apps that help merchants keep track of customer orders”) |")
w("| Primary tag | **Orders - Other** — this is an order follow-up workflow, not order tracking for customers, not order editing, not invoices. Picking *Order tracking* would be wrong: that tag is for apps that give order info **to customers**, and we never show anything to a customer. |")
w("| Secondary tag | **Cash on delivery (COD)** under *Selling products → Payment options* (“apps that help merchants collect and verify payment during delivery”) — that is literally the COD-confirmation flow. |")
w("| Structured features | Tick only what exists in the shipped build. Do not tick bulk actions / metafield editing / Functions to widen reach; the QA team checks classification and a mismatch is a re-work loop, not a ranking win. |")
w("")
w("### Pricing section\n")
w("| Item | Value |")
w("| --- | --- |")
w("| Primary billing method | **Recurring charge** (not “Free to install” — we have paid plans) |")
w("| Pricing model in the Dashboard | **Set up your own pricing.** This app creates the charge itself through the Billing API, which is what the Plan tab\u2019s buttons do. Choosing **Shopify App Pricing** instead switches who owns the whole flow: Shopify then refuses the app\u2019s own charges (so plan switching breaks with an error the merchant cannot fix), and its hosted plan page only appears once plans exist there. Pick one road; `php artisan taskpe:plans` prints which one the app thinks it is on. |")
w("| Plans | `Free`, `TaskPe Starter`, `TaskPe Growth`. The app\u2019s cards say `Free` / `Starter` / `Growth` and the charge Shopify shows the merchant is named `TaskPe Starter` (the mutation prefixes the app name), so the listing, the card and the approval page are the same three plans with the same numbers. Mark the Free plan as free \u2192 \u201cFree plan available\u201d shows in search; plans display lowest\u2192highest on their own. |")
w("| Plan handle | Nothing to fill in, in this mode. Those handles matter only if the app is priced with Shopify App Pricing instead; the app then learns them from the subscription itself and no `.env` line has to be right. |")
w("| Plan descriptions | Free: “2 team members, 50 open tasks, board and activity timeline, no WhatsApp.” Starter: “5 team members, unlimited tasks, WhatsApp alerts to staff, daily owner digest.” Growth: “Unlimited team members, priority support.” |")
w("| Trial | **7 days**, from `config/shopify.php \u2192 plans.*.trial_days`, and passed straight to `appSubscriptionCreate` — so the card, Shopify\u2019s confirmation page and this listing cannot disagree. Shopify *recommends* 14: change the config, regenerate this document, redeploy. |")
w("| Additional charges (per plan) | “WhatsApp messages are billed by your own Whatify account (about ₹0.12 per message), not by TaskPe.” |")
w("| Charges billed outside Shopify billing — link | [YOUR PAGE] explaining the Whatify cost. Requirement 4.2 wants outside charges disclosed with a link, and our app genuinely has one. |")
w("| Pricing page link | [YOUR PRICING PAGE] |")
w("| Prices shown here | **US dollars.** `AppRecurringPricingInput.price` permits exactly one currency code — USD — whatever the store is billed in, so the USD figure below is the amount of the charge and Shopify converts it for a non-US invoice. The app\u2019s Plan tab prints the same number and says so in words; the plan a store is on shows the amount read back from its own subscription. Two places have to agree: `config/shopify.php \u2192 plans` and this listing. The table below is generated from that config file — change the code and regenerate rather than retyping here. |")
w("")
w("#### The price table exactly as the Plan tab prints it (generated from `config/shopify.php`)\n")
w("| Plan | Price the app charges (USD) | Trial | What to enter in the Partner Dashboard |")
w("| --- | --- | --- | --- |")

for _pl in PLANS:
    _last = ('nothing — Free is the absence of a charge, and the app cancels the current one to get back to it'
             if not _pl['prices'] else
             'nothing — the app creates the charge from this number, and Shopify prorates an upgrade')

    w("| **%s** | %s | %s | %s |" % (
        _pl['name'],
        price_text(_pl),
        ('%d days' % _pl['trial']) if _pl['trial'] else 'none',
        _last,
    ))

w("")
w("An `INR` entry in `prices` is read only when the app is priced with Shopify App Pricing: in this mode the "
  "Dashboard sets a real per-currency price and the app merely displays it. It is **not** a charge this app "
  "could create — Shopify would answer \u201cCurrency code must be USD\u201d on the page where the merchant had "
  "already agreed to pay \u2014 so a rupee store is shown the USD figure with the line saying Shopify converts it "
  "at its own rate on the invoice. That sentence is the promise; nothing in the app claims a rupee price it "
  "cannot bill. `DEPLOYMENT.md` § 17 states what each number on a plan card is allowed to claim.")
w("")
w("### Links, support and eligibility\n")
w("| Field | Value |")
w("| --- | --- |")
w("| Privacy policy (required) | `https://<your-host>/privacy` — this route exists in the app. Shopify also wants it reachable without auth; `/privacy` is outside the embedded-app route group ✓ |")
w("| Terms of service | `https://[HOST]/terms` — the app serves it (`routes/web.php` → `AppController::terms`). Replace the two bracketed placeholders in `resources/views/terms.blade.php` (operating entity, governing law) before that URL goes in the form. |")
w("| Help / docs URL | [YOUR DOCS] — write Shopify-specific steps, per Polaris help-documentation guidance; the in-app Setup guide can be mirrored there |")
w("| Support email / phone | [SUPPORT EMAIL] / [PHONE] — also keep *emergency developer contact* current in the dashboard; Shopify pages that contact from there, not from the listing |")
w("| Demo store URL | [YOUR DEV STORE] — link **directly to the page that shows the app**, e.g. `https://<dev-store>.myshopify.com/admin/apps/taskpe`, and put the click path in the instructions below |")
w("| Sales channels needed | **Online Store** only. The app is admin-side and needs no POS. |")
w("| Storefront impact | **None**: no scripts, no theme files, no storefront API. Say this in the review notes — it removes performance questions entirely. |")
w("| Geography | Recommend **no country restriction**: billing is handled by Shopify in the store's currency, and the board works anywhere. Restrict only the *WhatsApp* wording in your copy (“alerts use Whatify, which serves India”) instead of locking merchants out — a hard restriction also locks you out of useful reviews. |")
w("")
w("---")
w("")
w("## 3. Media: files, spec and alt text\n")
w("Everything below is already in the repo, sized to spec:\n")
w("")
w("| Upload slot | File | Size | Status |")
w("| --- | --- | --- | --- |")
w("| App icon | `assets/listing/app-icon-1200.png` | **1200×1200** | ✓ built from the 1600 master (`assets/app-icon.png`). Shopify's spec is exactly 1200×1200, JPEG or PNG, **no text**, no Shopify marks. The art is a clipboard + tick with a ₹ coin; a currency symbol inside the pictogram is not “text”, but if you ever list outside India-first markets, ship a coin-free variant. |")
w("| Feature media (static image, since there is no video yet) | `assets/listing/feature-1600x900.png` | 1600×900 | ✓ this is the real board (`01-board.png`), which is exactly what 4.4.4 asks for. `assets/branding/feature-banner.png` is a designed graphic — keep it for social, not for the listing. |")
w("| Screenshots 1–6 (desktop) | `assets/screenshots/01-board.png` … `06-add-task.png` | each **1600×900** | ✓ real captures of the running app from `php artisan taskpe:demo-store`; no browser chrome, no pricing, no reviews, no PII, each image shows a different screen (4.4.5). First three become the preview strip, so order matters: board → templates → create-from-template. |")
w("| Mobile screenshot (optional slot) | `assets/listing/screenshot-mobile-900x1600.png` | **900×1600** | ✓ padded from the 780×1688 phone capture so it meets the spec without cropping the UI. |")
w("| Video (optional, 2–3 min) | — | — | Not recorded. Promo-style, ≤25% screencast, English audio or subtitles. Worth doing: it is the strongest field in 4.5. |")
w("")
w("**Before submitting, re-capture from a live store.** These were taken from the demo seeder, which is")
w("perfectly acceptable for review (and is what we tell the reviewer below), but Shopify's image rules are about")
w("*your app's UI* — a capture from a real install removes any argument. Two improvements to make while you're")
w("there: fill the **In Progress** column and add a 6th column so the wide screen doesn't sit empty in shot 1.")
w("")
w("### Alt text — one per image, two lengths\n")
w("Use the ≤100-character version where the field allows it; some listing fields cap lower, so the ≤64")
w("variant is provided. Descriptive, no keyword lists, no “screenshot of”.\n")
w("")
w("| Image | Alt text (≤100) | Short variant (≤64) |")
w("| --- | --- | --- |")
for fname, (a, b) in L['alt'].items():
    w("| `%s` | %s | %s |" % (fname, a, b))
w("")
w("---")
w("")
w("## 4. API access requests → Protected customer data\n")
w("Path: **Partner Dashboard → Apps → TaskPe → API access requests → Protected customer data access → Request access**.")
w("You must select a distribution method (Shopify App Store) before the request is offered.\n")
w("")
w("| Step | Tick / type |")
w("| --- | --- |")
w("| 4. Select **Protected customer data**, provide reasons | **Yes — request Level 1** (order/draft-order data, no name/email/phone/address). Paste **Reason A** below. |")
w("| 5. Select protected customer **fields** | **Decide first — see the fork below.** Default recommendation: tick **none** of Name / Address / Email / Phone. |")
w("| 6. **Data protection details** | Answer from section 5 below — every requirement is already met in code, four items need your hosting facts. |")
w("| 7. Submit for review | With the app submission. |")
w("")
w("### The one decision to make before you paste\n")
w("The order search is **Level 1**: `id name createdAt displayFinancialStatus displayFulfillmentStatus totalPriceSet`")
w("— no identifying field. But the picker's **customer tab** reads `Customer.displayName`, and `displayName` is")
w("derived from `firstName`/`lastName`, i.e. a *name* — that is a **Level 2** field with its own approval and the")
w("level-2 requirements on top.\n")
w("")
w("| Option | What to submit | Cost |")
w("| --- | --- | --- |")
w("| **A — Level 1 only (recommended)** | Request protected customer data; tick **no** fields; scopes `read_orders, read_products, read_content` | Four one-line edits, already mapped out: the tab list in `public/js/app.js` (`types`), the `Rule::in([...])` list and the `match` arm in `ResourceSearchController`, and `read_customers` out of `SHOPIFY_SCOPES` + `shopify.app.toml`. Fastest review, and the strongest possible answer to “minimum data required” — nothing in the workflow needs a customer record, because tasks link to *orders*. |")
w("| **B — keep the customer tab** | Request protected customer data **plus the Name field**, with Reason B below, and complete the Level 2 items in section 5 | Approval takes longer and can pull a data protection review (they focus on install count, record volume, approved fields and retention). If rejected, you must remove the feature anyway. |")
w("")
w("### Reason A — paste into the “Protected customer data” reason box\n")
w("")
w("Short version (use if the box caps length tightly; ~540 characters):\n")
w("```text")
w(L['pcd_short'])
w("```")
w("")
w("Long version (preferred — it answers the four questions a reviewer asks: what, why, where, when deleted):\n")
w("```text")
w(L['pcd_long'])
w("```")
w("")
w("### Reason B — only if you keep the customer tab (Name field)\n")
w("```text")
w(L['name_field'])
w("```")
w("")
w("### Scope-by-scope justification (same facts, per scope — some forms ask for one reason per scope)\n")
w("| Scope | Reason |")
w("| --- | --- |")
w("| `read_orders` | Find and link the order a follow-up task is about: id, name, created date, financial/fulfilment status, total. Never writes, never line items, never contact fields. |")
w("| `read_products` | The picker's product tab: id, title, status, featured image — so a “photograph this SKU” task carries the right product. |")
w("| `read_content` | The picker's blog-article tab: id and title only, for content follow-up tasks. |")
w("| `read_customers` | **Only** the customer tab label (`displayName`). If you take option A, delete this scope from `SHOPIFY_SCOPES` and `shopify.app.toml`. |")
w("| `write_*` | **None requested.** The app writes nothing to the store: no metafields, no tags, no order edits. Say this out loud in the submission — it answers a whole category of objections. |")
w("")
w("## 5. Data protection details — answer per requirement\n")
w("The dashboard checklist mirrors the documented requirements. Each answer below is what the code does")
w("today, with the file to point at if asked. `[CONFIRM]` = only you can answer that from your hosting.\n")
w("")
for head, body in DP:
    w("**" + head + "**  ")
    w(body + "\n")
w("")
w("## 6. `read_all_orders` — “describe your app and why you're applying for access”\n")
w("Path: **API access requests → Read all orders scope → Request access → Orders page**.\n")
w("")
w("```text")
w(L['all_orders'])
w("```")
w("")
w("Notes for the same form: keep the app version released with `read_all_orders` in")
w("`shopify.app.toml` → `[access_scopes]` **after** approval, set `SHOPIFY_READ_ALL_ORDERS=true`,")
w("`php artisan config:clear`, then have the store reinstall (the scope only lands on a fresh install).")
w("`php artisan taskpe:doctor <shop>` prints which of those three is still missing — quote it in the")
w("review notes if the request comes back with questions. Until approved, the app says so in the picker")
w("and lets the merchant link the order by hand, so nothing is misrepresented while you wait.\n")
w("")
w("## 7. App review instructions (4.5) — paste as-is\n")
w("This is **`shopify-test-instructions.txt`, verbatim** — that file is what you paste into the *App review")
w("instructions* box, and it is the source of this section: edit the text file, then run")
w("`python3 tools/listing-copy.py && python3 tools/make-docx.py` and both documents follow. Every step states")
w("what to click **and** what to expect, because a reviewer who has to guess the expected result writes a")
w("question instead of an approval; the bracketed `[ ... ]` bits are the credentials only you can fill.\n")
w("```text")
w(TEST)
w("```")
w("")
w("**Screenshot → step mapping** (add it under the box if the form has room): shots 2 and 3 = step 2,")
w("shot 4 = step 3, shot 5 = step 2 and step 4, shot 6 = step 2, mobile shot = step 7.")
w("If you take option A in section 4 (no customer tab), re-capture shot 5 so the Customers tab is gone —")
w("a listing image must not show a picker entry the app no longer has.\n")
w("")
w("## 8. Rejection radar (read this before you press submit)\n")
w("")
w("| Risk | Fix |")
w("| --- | --- |")
for a, b in RADAR:
    w("| " + a + " | " + b + " |")
w("")
w("## 9. Field-length report (generated — regenerate after any copy edit)\n")
w("")
w("This file and the report above are produced by `python3 tools/listing-copy.py`, which **refuses to write**")
w("if any field exceeds its Shopify limit. Edit the copy there, not here, and the limits stay true.")
w("")
w("| Field | Length | Limit |")
w("| --- | --- | --- |")
for label, n, cap in rows:
    w("| %s | %d | %d |" % (label, n, cap))
w("")
w("---")
w("")
w("### Still to fill before submitting\n")
w("1. Both legal pages exist — `https://<host>/privacy` and `https://<host>/terms`. Fill the two placeholders in `resources/views/terms.blade.php` (operating entity, governing law), then link both.")
w("placeholders in `resources/views/terms.blade.php` (operating entity, governing law), then link both.")
w("2. Support email, docs URL, demo store URL, emergency developer contact. `SHOPIFY_APP_HANDLE` in `.env` is only needed if the app is priced with Shopify App Pricing instead (the 1.2.3 row above); in `api` mode the app creates the charge itself and no hosted plan page is involved.")
w("3. Pricing page that explains charges billed outside Shopify (Whatify).")
w("4. Decide the Level 1 / Level 2 fork in section 4, and if Level 1: remove the customer tab + `read_customers`.")
w("5. Optional but high-value: the 2–3 minute feature video, and re-capturing screenshots from a real store.")
w("")

io.open('APP-LISTING.md', 'w', encoding='utf8').write("\n".join(out) + "\n")
print('\nwrote APP-LISTING.md (%d lines)' % len(out))
