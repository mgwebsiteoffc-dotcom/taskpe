<?php

/*
|--------------------------------------------------------------------------
| Shopify public app configuration
|--------------------------------------------------------------------------
| Every value here is driven by .env so the same codebase can be deployed
| for review, staging and production. See README.md for the Partner
| Dashboard values that must mirror these settings.
*/

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
    'scopes' => env('SHOPIFY_SCOPES', 'read_orders,read_products,read_customers,read_content'),

    // Webhooks registered automatically on install (topic => handled internally).
    'webhook_topics' => [
        'APP_UNINSTALLED',
        'CUSTOMERS_DATA_REQUEST',   // mandatory GDPR
        'CUSTOMERS_REDACT',         // mandatory GDPR
        'SHOP_REDACT',              // mandatory GDPR
        'ORDERS_CREATE',            // optional COD auto-task (gated in-app by automation.cod_auto)
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing (Shopify Billing API — mandatory for public apps; never charge
    | through Razorpay/Stripe/etc. for the app subscription itself)
    |--------------------------------------------------------------------------
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
    'default_plan' => 'free',

    // Currency used when a plan has no entry for the shop's billing currency.
    'billing_fallback_currency' => 'USD',

    // Default board created on install.
    'default_columns' => ['To Do', 'In Progress', 'Done'],
];
