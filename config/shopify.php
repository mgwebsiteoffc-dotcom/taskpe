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
    | Read the "Who owns the price" block below first: when billing.mode is
    | `shopify` (the default) none of this table is displayed or sent anywhere,
    | because the Partner Dashboard already priced the plan in the store's own
    | currency. It only means money in `api` mode.
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
            'trial_days'       => 7,
            'member_limit'     => 5,
            'task_limit'       => 0,                  // 0 = unlimited
            'whatsapp'         => true,
            'digest'           => true,
        ],
        'growth' => [
            'name'             => 'Growth',
            'prices'           => ['USD' => 11.99, 'INR' => 999],
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
        'plans_url' => rtrim((string) env('SHOPIFY_APP_PLANS_URL', ''), '/'),
    ],

    'default_plan' => 'free',

    // Currency used when a plan has no entry for the shop's billing currency.
    'billing_fallback_currency' => 'USD',

    // Default board created on install.
    'default_columns' => ['To Do', 'In Progress', 'Done'],
];
