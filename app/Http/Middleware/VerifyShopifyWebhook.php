<?php

namespace App\Http\Middleware;

use App\Support\ShopifyHmac;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mandatory for public apps: verify X-Shopify-Hmac-Sha256 against the RAW
 * request body before touching the payload.
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $valid = ShopifyHmac::verifyWebhook(
            $request->getContent(),
            $request->header('X-Shopify-Hmac-Sha256')
        );

        if (!$valid) {
            return response()->json(['error' => 'invalid_hmac'], 401);
        }

        return $next($request);
    }
}
