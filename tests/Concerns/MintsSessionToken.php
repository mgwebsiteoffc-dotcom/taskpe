<?php

namespace Tests\Concerns;

/**
 * Minimal, correctly-shaped App Bridge session token (HS256, signed with the
 * app secret from config) so feature tests can exercise the same
 * VerifyShopifySessionToken path the embedded SPA and the Admin extensions use.
 */
trait MintsSessionToken
{
    protected function sessionToken(string $shop = 'demo-store.myshopify.com'): string
    {
        $enc = static fn (array $v): string => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $now = time();

        $header = $enc(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => 'shopify-token']);
        $payload = $enc([
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => (string) config('shopify.api_key'),
            'sub' => '1234567890',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now,
            'jti' => 'test-jti',
            'sid' => 'test-sid',
        ]);

        $signature = rtrim(strtr(base64_encode(hash_hmac(
            'sha256', "{$header}.{$payload}", (string) config('shopify.api_secret'), true
        )), '+/', '-_'), '=');

        return "{$header}.{$payload}.{$signature}";
    }

    /** Same token, as the header pair the SPA sends (see .htaccess notes). */
    protected function withSessionToken(string $shop = 'demo-store.myshopify.com'): self
    {
        $token = $this->sessionToken($shop);

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-TaskPe-Auth' => $token,
        ]);
    }
}
