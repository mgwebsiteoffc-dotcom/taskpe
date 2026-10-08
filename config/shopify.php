<?php

/*
|--------------------------------------------------------------------------
| Shopify public app configuration
|--------------------------------------------------------------------------
| Every value here is driven by .env so the same codebase can be deployed
| for review, staging and production. See README.md for the Partner
| Dashboard values that must mirror these settings.
*/

/*
|--------------------------------------------------------------------------
| Full order history (`read_all_orders`)
|--------------------------------------------------------------------------
| Computed BEFORE the array because the two values must agree: the flag says whether
| the app may ask for the scope, and the scope list is what OAuth sends to the store.
|
| Flip SHOPIFY_READ_ALL_ORDERS=true ONLY after Partner Dashboard → your app →
| API access → Protected customer data has approved reading all orders — asking before
| that approval makes the install screen fail for every store, which is a worse outage
| than a search that can only see recent orders. After the approval it is one .env line,
| `php artisan config:clear`, and a reinstall per store; the bounded-search notice in
| the picker reads granted scopes and disappears on its own. See DEPLOYMENT.md § 15.
*/
$readAllOrders = filter_var(env('SHOPIFY_READ_ALL_ORDERS', false), FILTER_VALIDATE_BOOLEAN);

$requested = explode(',', (string) env('SHOPIFY_SCOPES', 'read_orders,read_products,read_customers,read_content'));

if ($readAllOrders) {
    $requested[] = 'read_all_orders';
}

$scopes = implode(',', array_values(array_unique(array_filter(array_map('trim', $requested)))));

return [

    // From Partner Dashboard → Apps → your app → Overview → Client credentials.
    'api_key'    => env('SHOPIFY_API_KEY', ''),
    'api_secret' => env('SHOPIFY_API_SECRET', ''),

    // Public HTTPS URL of this Laravel app (no trailing slash).
    'app_url' => rtrim((string) env('APP_URL', ''), '/'),

    /*
    |--------------------------------------------------------------------------
    | Expiring offline access tokens (required for public apps)
    |--------------------------------------------------------------------------
    | The GraphQL Admin API rejects a NON-expiring offline token for a public app, and the
    | expiring one lives an hour: `expires_in` from the response is what says how long, and
    | the refresh token beside it is how App\Services\TokenVault renews it without a merchant.
    | The values below are the two knobs, and lifetimes are deliberately NOT configured here
    | — Shopify's answer says `expires_in`, so Shopify is the one that decides.
    |
    | SHOPIFY_TOKEN_REFRESH_SKEW  how early (seconds) a call renews instead of using a token
    |                             about to die. Below ~60s the renew and the request race each
    |                             other in a slow queue; 120 suits an hour-long token.
    | SHOPIFY_TOKEN_MIGRATE_RETRY how long to sit out after a store's legacy token turns out
    |                             not to be convertible (custom-app and merchant-built tokens
    |                             are exempt from the change, so a rejection there is normal).
    */
    'token_refresh_skew'   => (int) env('SHOPIFY_TOKEN_REFRESH_SKEW', 120),
    'token_migrate_retry'  => (int) env('SHOPIFY_TOKEN_MIGRATE_RETRY', 3600),
    'token_lock_seconds'   => (int) env('SHOPIFY_TOKEN_LOCK_SECONDS', 25),

    /*
    |--------------------------------------------------------------------------
    | API version
    |--------------------------------------------------------------------------
    | Public apps must stay on a supported stable version. Pin it here and
    | set the SAME version in Partner Dashboard for webhooks. Upgrade on
    | Shopify's quarterly release cycle (YYYY-01 / 04 / 07 / 10).
    */
    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    /*
    |--------------------------------------------------------------------------
    | Access scopes
    |--------------------------------------------------------------------------
    | Keep these minimum-viable. read_orders / read_customers touch protected
    | customer data — see README.md "Protected customer data" for the exact
    | Partner Dashboard configuration this app was designed for (we never
    | query or store PCD fields such as customer address/email/phone).
    */
    /*
    | Full order history — see the $readAllOrders block at the top of this file. Shopify's
    | protected-customer-data rule: without the `read_all_orders` scope an offline token may
    | read only orders created AFTER the install, so an older order cannot be found by any
    | search, however well written. That scope is refused at install until the review
    | approves it, so it is not in the default list.
    */
    'read_all_orders' => $readAllOrders,
    'scopes'          => $scopes,   // the env list, plus read_all_orders when approved

    // Webhooks registered automatically on install (topic => handled internally).
    'webhook_topics' => [
        'APP_UNINSTALLED',
        'CUSTOMERS_DATA_REQUEST',   // mandatory GDPR
        'CUSTOMERS_REDACT',         // mandatory GDPR
        'SHOP_REDACT',              // mandatory GDPR
        'ORDERS_CREATE',            // optional COD auto-task (gated in-app by automation.cod_auto)
        'APP_SUBSCRIPTIONS_UPDATE', // the Dashboard may change or cancel the plan with nobody in the app
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing (Shopify Billing API — mandatory for public apps; never charge
    | through Razorpay/Stripe/etc. for the app subscription itself)
    |--------------------------------------------------------------------------
    | Read the "Who owns the price" block below first. In `api` mode these numbers
    | ARE the charge. In `shopify` mode (the default) the Partner Dashboard's plan
    | is what gets invoiced, and this table is still shown — as a LIST price, next to
    | a line saying so — because a Plan page with no numbers on it is a merchant
    | asking "what does this cost before I click". Two consequences:
    |
    |   1. keep the amount for a currency in step with the Dashboard plan, or the app
    |      shows a price the invoice contradicts. `php artisan taskpe:plans` compares
    |      them per store and exits non-zero while they disagree;
    |   2. add the store's own billing currency here when you have it. A USD amount
    |      shown to a store billed in another currency is labelled as converted, but
    |      an exact entry is always the better page to read.
    |
    | Since the 2023-04 API, app charges may be created in the MERCHANT'S
    | BILLING CURRENCY — so Indian stores approve "₹499/mo" directly, with no
    | FX conversion on their Shopify invoice. For every other currency the
    | USD price is used. Add more keys to `prices` to localize further
    | (e.g. 'GBP' => 4.99, 'EUR' => 5.49, ...).
    */
    'plans' => [
        'free' => [
            'name'             => 'Free',
            'prices'           => [],                 // no charge
            'trial_days'       => 0,
            'member_limit'     => 2,
            'task_limit'       => 50,
            'whatsapp'         => false,
            'digest'           => false,
        ],
        'starter' => [
            'name'             => 'Starter',
            'prices'           => ['USD' => 5.99, 'INR' => 499],
            // Shopify's own handle for this App Pricing plan. With it, the in-app switcher can
            // open Shopify's approval page FOR THAT PLAN; without it the merchant lands on the
            // plan list and picks there. The app also learns handles from the `?plan_handle=`
            // parameter Shopify puts on the return redirect, so this line is only needed to skip
            // that first trip — see DEPLOYMENT.md § 17. Leave empty to use the plan list.
            'plan_handle'      => env('TASKPE_PLAN_STARTER_HANDLE', ''),
            'trial_days'       => 7,
            'member_limit'     => 5,
            'task_limit'       => 0,                  // 0 = unlimited
            'whatsapp'         => true,
            'digest'           => true,
        ],
        'growth' => [
            'name'             => 'Growth',
            'prices'           => ['USD' => 11.99, 'INR' => 999],
            'plan_handle'      => env('TASKPE_PLAN_GROWTH_HANDLE', ''),
            'trial_days'       => 7,
            'member_limit'     => 0,
            'task_limit'       => 0,
            'whatsapp'         => true,
            'digest'           => true,
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | Who owns the price
    |--------------------------------------------------------------------------
    | `shopify` (default) — the plans, their prices and their trials are created
    | in the Partner Dashboard (Shopify App Pricing). Shopify then bills the
    | merchant in the store's own currency, and the app must not create charges
    | OR display its own numbers: a card that reads ₹499 while the Dashboard plan
    | says $5.99 is not a rounding detail, it is a price the merchant will never
    | be charged. In this mode the Plan tab reports what Shopify says it bills,
    | and `appSubscriptionCreate` is never called (Shopify rejects it for apps
    | with Dashboard-managed plans, and the merchant would only see their API's
    | error text).
    |
    | `api` — legacy: the `prices` map under each plan IS the price table and the
    | app creates the subscription itself. Kept for apps whose plans are not
    | registered in the Dashboard.
    */
    'billing' => [
        'mode'      => strtolower((string) env('SHOPIFY_BILLING_MODE', 'shopify')),

        /*
        |--------------------------------------------------------------------------
        | Where a merchant changes plan
        |--------------------------------------------------------------------------
        | Shopify App Pricing (the mode this app ships in) means Shopify owns the plans AND
        | hosts the plan selection page. Requirement 1.2.3 is that a merchant can upgrade and
        | downgrade without contacting support and without reinstalling — so the app has to
        | actually send them to that page. Its documented pattern is:
        |
        |   https://admin.shopify.com/store/{store handle}/charges/{app handle}/pricing_plans
        |
        | The store handle is already on the shop row (taken from the domain at OAuth, and
        | re-derived from the domain when an old row has an empty one), so the
        | only missing piece is OUR app handle: Partner Dashboard → App → Settings → General →
        | App handle (the same string as `handle` in shopify.app.toml, and the last part of an
        | app URL). Set SHOPIFY_APP_HANDLE and every Plan card gets a working button.
        | SHOPIFY_APP_PLANS_URL overrides the whole URL when Shopify gives you a different one.
        */
        /*
        | One more thing about that handle: a value one character off does not look broken. Shopify
        | answers an unknown address by opening its Apps list, which reads to a merchant as "the plan
        | button ignored me". So the app also ASKS Shopify what its handle is
        | (`currentAppInstallation.app.handle`, cached per store by BillingService::reportedAppHandle)
        | and prefers that answer over SHOPIFY_APP_HANDLE. `php artisan taskpe:plans --links` prints
        | both, says which one a link was built from, and flags the disagreement.
        */
        'app_handle' => strtolower(trim((string) env('SHOPIFY_APP_HANDLE', ''))),
        'plans_url'  => rtrim((string) env('SHOPIFY_APP_PLANS_URL', ''), '/'),

        /*
        | Which form of that address to build, when it is not given outright by SHOPIFY_APP_PLANS_URL.
        |
        |   admin      https://admin.shopify.com/store/{store}/charges/{app}/pricing_plans  (documented)
        |   myshopify  https://{shop}.myshopify.com/admin/charges/{app}/pricing_plans — the same page
        |              through the store's own domain, so nothing depends on the store slug matching
        |              the admin handle (renamed stores, transfers, dev stores where the two differ).
        |
        | Switch only when `taskpe:plans --links` says the app handle is VERIFIED and Shopify still
        | bounces the `admin` form. It changes how the link is spelled and nothing else.
        */
        'plans_url_style' => strtolower(trim((string) env('SHOPIFY_PLANS_URL_STYLE', 'admin'))),
    ],

    'default_plan' => 'free',

    // Currency used when a plan has no entry for the shop's billing currency.
    'billing_fallback_currency' => 'USD',

    // Default board created on install.
    'default_columns' => ['To Do', 'In Progress', 'Done'],
];
