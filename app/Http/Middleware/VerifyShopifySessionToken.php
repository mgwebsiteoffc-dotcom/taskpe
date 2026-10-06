<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Support\JwtToken;
use App\Support\ShopContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2026 rule for embedded apps: authenticate every XHR with the App Bridge
 * session token (JWT), never with cookies. This middleware verifies the
 * token, loads the installed shop and stashes it in ShopContext so every
 * query in the request is tenant-scoped.
 */
class VerifyShopifySessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);
        if (!$token) {
            // Most of the time this is not the merchant's fault: LiteSpeed/Apache on
            // shared hosting drop the Authorization header before PHP sees it,
            // so a perfectly good session token never arrives. public/.htaccess
            // + the X-TaskPe-Auth twin in app.js work around it; the distinct
            // error code lets the SPA say that out loud instead of "reload".
            return response()->json([
                'error'   => 'missing_session_token',
                'message' => 'No Shopify session token reached the server. If this happens inside Shopify Admin, the host is stripping the Authorization header — keep the .htaccess CGIPassAuth/HTTP_AUTHORIZATION rules in sync.',
            ], 401);
        }

        try {
            $payload = JwtToken::verifyShopifySessionToken($token);
        } catch (\Throwable $e) {
            // `reason` is a JwtToken string (alg/signature/exp/aud/iss-dest) —
            // it tells us which of the two secrets is wrong without leaking it.
            return response()->json([
                'error'   => 'invalid_session_token',
                'reason'  => $e->getMessage(),
                'message' => 'Shopify session token could not be verified — check SHOPIFY_API_KEY / SHOPIFY_API_SECRET and APP_URL match the app in the Partner Dashboard.',
            ], 401);
        }

        $shop = Shop::where('domain', $payload['_shop_domain'])->first();
        if (!$shop || !$shop->isInstalled()) {
            // Front-end listens for this code and restarts OAuth at top level.
            return response()->json(['error' => 'not_installed', 'shop' => $payload['_shop_domain'], 'code' => 'reauth'], 401);
        }

        // Optional "who is acting" chip (Indian teams often share a device) —
        // validated against the tenant so it can never cross shops.
        $actor = null;
        $memberId = (int) $request->header('X-TaskPe-Member', 0);
        if ($memberId > 0) {
            $actor = $shop->members()->where('active', true)->find($memberId);
        }

        app(ShopContext::class)->set($shop, $payload, $actor);
        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    /**
     * Session token, from whichever place the web server left it.
     *
     * Order: the proper Authorization header → the SPA's dedicated twin header
     * → the env vars the Apache/LiteSpeed rewrite rules populate. All of them are
     * the same JWT; nothing here trusts client input beyond what the header
     * already allows, because the value is HMAC-verified against the app secret.
     */
    protected function bearerToken(Request $request): ?string
    {
        foreach ([
            $request->bearerToken(),
            $request->header('X-TaskPe-Auth'),
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['Authorization'] ?? null,
        ] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $candidate = trim($candidate);
            if (preg_match('/^(?:Bearer|token)\s+(.+)$/i', $candidate, $m)) {
                $candidate = trim($m[1]);
            }

            // A JWT is three dot-separated base64url segments; anything else
            // (e.g. a Basic header from a crawler) is not ours.
            if ($candidate !== '' && substr_count($candidate, '.') === 2) {
                return $candidate;
            }
        }

        return null;
    }
}
