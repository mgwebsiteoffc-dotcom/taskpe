<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * HMAC verification for OAuth callbacks and webhook payloads.
 * Shopify signs with the app secret; timing-safe comparison always.
 */
class ShopifyHmac
{
    /**
     * Verify the OAuth callback query string (&hmac=...).
     * Builds the message from the RAW query string to avoid PHP mangling
     * duplicate/encoded params.
     */
    public static function verifyOAuthCallback(Request $request): bool
    {
        $pairs = [];
        foreach (explode('&', (string) $request->server->get('QUERY_STRING')) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if ($k === 'hmac') {
                continue;
            }
            // Shopify computes over raw values with '&' and '%' handled as-is.
            $pairs[] = $k . '=' . $v;
        }
        sort($pairs, SORT_STRING);

        $calc = hash_hmac('sha256', implode('&', $pairs), (string) config('shopify.api_secret'));

        return hash_equals($calc, (string) $request->query('hmac', ''));
    }

    /**
     * Verify a webhook: X-Shopify-Hmac-Sha256 = base64(hmac_sha256(rawBody)).
     */
    public static function verifyWebhook(string $rawBody, ?string $headerBase64): bool
    {
        if (!$headerBase64) {
            return false;
        }
        $calc = base64_encode(hash_hmac('sha256', $rawBody, (string) config('shopify.api_secret'), true));

        return hash_equals($calc, $headerBase64);
    }
}
