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
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['error' => 'missing_session_token'], 401);
        }

        try {
            $payload = JwtToken::verifyShopifySessionToken($token);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid_session_token', 'reason' => $e->getMessage()], 401);
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
}
