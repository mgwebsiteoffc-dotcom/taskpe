<?php

namespace App\Support;

/**
 * Minimal, dependency-free verifier for Shopify App Bridge session tokens.
 *
 * Session tokens are JWTs signed HS256 with the app secret. Validating them
 * server-side is the 2026 requirement for embedded apps (no cookie auth).
 *
 * Spec checks enforced: alg allow-list, signature, exp/nbf, aud == api key,
 * iss == "https://{shop}/admin", dest == "https://{shop}".
 */
class JwtToken
{
    /** @return array<string,mixed> validated payload */
    public static function verifyShopifySessionToken(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Malformed token');
        }

        [$h64, $p64, $sig64] = $parts;
        $header  = json_decode(self::b64urlDecode($h64) ?: '', true);
        $payload = json_decode(self::b64urlDecode($p64) ?: '', true);

        if (!is_array($header) || !is_array($payload)) {
            throw new \InvalidArgumentException('Malformed token JSON');
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new \InvalidArgumentException('Unexpected alg');
        }

        $expected = self::b64urlEncode(hash_hmac('sha256', "$h64.$p64", (string) config('shopify.api_secret'), true));

        if (!hash_equals($expected, $sig64)) {
            throw new \InvalidArgumentException('Bad signature');
        }

        $now = time();
        if (empty($payload['exp']) || $now >= (int) $payload['exp']) {
            throw new \InvalidArgumentException('Token expired');
        }
        if (isset($payload['nbf']) && $now < (int) $payload['nbf'] - 5) {
            throw new \InvalidArgumentException('Token not yet valid (nbf)');
        }

        $aud = $payload['aud'] ?? null;
        $audOk = is_array($aud)
            ? in_array(config('shopify.api_key'), $aud, true)
            : $aud === config('shopify.api_key');
        if (!$audOk) {
            throw new \InvalidArgumentException('aud mismatch');
        }

        $dest = (string) ($payload['dest'] ?? '');
        $iss  = (string) ($payload['iss'] ?? '');
        $shop = parse_url($dest, PHP_URL_HOST) ?: '';
        if ($shop === '' || $iss !== "https://{$shop}/admin" || !self::isValidShopDomain($shop)) {
            throw new \InvalidArgumentException('iss/dest mismatch');
        }

        $payload['_shop_domain'] = $shop;

        return $payload;
    }

    public static function isValidShopDomain(string $shop): bool
    {
        // Accept myshopify.com domains (and myshopify.io dev domains).
        return (bool) preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-]*\.(myshopify\.com|myshopify\.io)$/', $shop);
    }

    public static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): ?string
    {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($data, '-_', '+/'), true);

        return $out === false ? null : $out;
    }
}
