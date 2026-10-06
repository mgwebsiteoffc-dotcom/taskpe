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
| fail loudly ("Auth guard [] is not defined") instead of half-working.
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
