<?php

/*
|--------------------------------------------------------------------------
| CORS — required for Shopify Admin UI Extensions
|--------------------------------------------------------------------------
| Admin action extensions ("Create task" on Order/Product/Customer pages)
| run inside Shopify's sandboxed iframe on a shopifycdn.com origin, so their
| fetch() calls to this backend are cross-origin.
|
| Safe because: authentication is a Bearer session-token JWT on EVERY call —
| never cookies — so a wildcard origin cannot ride a user's session the way
| cookie-auth APIs can. Web routes ("/") are deliberately NOT listed.
*/

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    // X-TaskPe-Auth is the Authorization twin the SPA sends for hosts that
    // strip Authorization before PHP (CGI/FastCGI/LiteSpeed). Same JWT, same
    // verification — it is not a second, weaker auth surface.
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'X-TaskPe-Member', 'X-TaskPe-Auth'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,
];
