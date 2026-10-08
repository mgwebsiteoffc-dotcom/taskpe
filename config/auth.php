<?php

/*
|--------------------------------------------------------------------------
| Authentication — nothing in this app uses Laravel's user auth
|--------------------------------------------------------------------------
| TaskPe has no users table and no User model, on purpose:
|
|   • Store admins  → Shopify App Bridge session token (HS256 JWT), verified
|                      by app/Http/Middleware/VerifyShopifySessionToken.php.
|   • Staff portal   → signed per-member portal cookie, verified by
|                      app/Http/Middleware/StaffPortalAuth.php.
|
| Neither is a Laravel guard and neither stores a password. The skeleton's
| `web` guard pointed at App\Models\User, which does not exist here — a stray
| auth() call died with a confusing "class not found" and `php artisan
| db:seed` died on the phantom User factory. On a fresh deploy both read as
| "the app is broken", which is exactly the noise that hides the real failure.
|
| Empty arrays keep config caching valid and make any accidental auth() call
| fail loudly instead of half-working — with ONE exception the framework asks for
| itself: `throttle:` middleware calls `$request->user()` before it looks at the IP,
| and an empty guard list turns that ordinary question into
| "Auth guard [] is not defined." on every throttled POST (that is what killed staff
| WhatsApp sign-in and the courier NDR intake). So `web` and `api` groups lead with
| app/Http/Middleware/NoLaravelUser.php, which answers "nobody" — the truth here —
| and the loud failure is preserved for any real `auth()->guard('…')` call. If you
| ever add a guard, delete that middleware rather than keeping both.
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', null),
        'passwords' => env('AUTH_PASSWORD_BROKER', null),
    ],

    'guards' => [],

    'providers' => [],

    'passwords' => [],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
