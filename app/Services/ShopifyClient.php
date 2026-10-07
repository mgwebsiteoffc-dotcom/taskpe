<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Shopify Admin API client.
 *
 * - GraphQL first (2026 guidance), REST only where OAuth requires it.
 * - Retries 429 / THROTTLED / 5xx with backoff — public apps must respect
 *   rate limits.
 */
class ShopifyClient
{
    public function __construct(protected Shop $shop) {}

    protected function apiBase(): string
    {
        return "https://{$this->shop->domain}/admin/api/".config('shopify.api_version');
    }

    /**
     * Run a GraphQL query/mutation against the Admin API.
     *
     * @throws \RuntimeException on transport or userError failure
     */
    public function graphql(string $query, array $variables = [], bool $tolerateUserErrors = false): array
    {
        $attempt = 0;
        $maxAttempts = 4;
        $recovered = false;

        while (true) {
            $attempt++;

            // Not `$this->shop->access_token`: an offline token now lives an hour, so the
            // header has to come from the place that knows how to renew it. Every caller
            // in the app — install, webhooks, billing read-back, the daily digest, this
            // search — inherits rotation from that one line.
            $response = Http::timeout(20)
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken()])
                ->post($this->apiBase().'/graphql.json', [
                    'query'     => $query,
                    'variables' => (object) $variables,
                ]);

            if ($response->status() === 429 || $response->serverError()) {
                if ($attempt >= $maxAttempts) {
                    throw new \RuntimeException('Shopify API unavailable (HTTP '.$response->status().')');
                }
                usleep($this->backoff($attempt, (int) $response->header('Retry-After', 0)));
                continue;
            }

            $json = $response->json();

            // Cost-based throttling arrives as a 200 with errors[].extensions.code = THROTTLED.
            $throttled = collect($json['errors'] ?? [])->contains(
                fn ($e) => ($e['extensions']['code'] ?? null) === 'THROTTLED'
            );
            if ($throttled && $attempt < $maxAttempts) {
                usleep($this->backoff($attempt));
                continue;
            }

            // A dead grant comes back 401 with {"errors":"[API] Invalid API key or access
            // token (unrecognized login or wrong password)"} — a STRING, not a list. No
            // retry and no GraphQL fix helps: only OAuth mints a new token, so flag the shop
            // (which routes the merchant straight to reconnect), then say that.
            $errorText = isset($json['errors'])
                ? (is_array($json['errors']) ? json_encode($json['errors']) : (string) $json['errors'])
                : '';

            // Shopify's words for a grant that predates the expiring-token change:
            //   [API] Non-expiring access tokens are no longer accepted for the Admin API.
            // It is neither a scope problem nor a reason to reinstall — the stored token can
            // be converted in place — so try that and replay this call once. Same ladder for
            // a plain 401: refresh first, and only complain when renewal is impossible.
            // One recovery per call, because a store that genuinely cannot renew itself must
            // not turn a board load into a loop of token posts.
            if (!$recovered && $errorText !== '' && (
                    preg_match('/non-expiring|expiring offline|no longer accepted/i', $errorText) === 1
                    || $response->status() === 401
                )) {
                $recovered = true;

                try {
                    if ((new TokenVault($this->shop))->renewNow()) {
                        Log::info('Replayed one Admin API call on a renewed token', [
                            'shop'   => $this->shop->domain,
                            'reason' => $response->status() === 401 ? 'expired' : 'non-expiring token refused',
                        ]);

                        continue;
                    }
                } catch (\RuntimeException $e) {
                    // The vault already flagged the store and wrote the log line; its message
                    // is the merchant-facing one, so it is what the screen should show.
                    throw $e;
                }
            }

            $rejected = preg_match('/Invalid API key or access token|unrecognized login/i', $errorText) === 1
                // A 401 *with* a Shopify `errors` body is the dead grant. A 401 whose body is
                // HTML (edge/proxy/WAF) must not flag a working install into a reconnect loop,
                // so it falls through to the generic error below instead.
                || ($response->status() === 401 && $errorText !== '');

            if ($rejected) {
                $why = 'Shopify rejected the stored access token ('.($errorText ?: 'HTTP 401').')';
                $this->shop->markTokenRejected($why);

                Log::warning('Shopify rejected this install\'s access token', [
                    'shop'        => $this->shop->domain,
                    'client_id'   => substr((string) config('shopify.api_key'), 0, 8).'…',
                    'token'       => $this->shop->tokenFingerprint(),
                    'api_version' => (string) config('shopify.api_version'),
                    'reply'       => $errorText ?: 'HTTP '.$response->status(),
                    'next_step'   => 'Open the app from Shopify admin to run OAuth again (or php artisan taskpe:doctor '.$this->shop->domain.' to verify).',
                ]);

                throw new \RuntimeException($why.'. Reconnect the store (Apps → TaskPe) so Shopify issues a new token.', 401);
            }

            if (!empty($json['errors'])) {
                Log::warning('Shopify GraphQL errors', ['shop' => $this->shop->domain, 'errors' => $errorText]);
                throw new \RuntimeException('Shopify GraphQL error: '.$errorText);
            }

            // Surface mutation userErrors (validation, ALREADY_TAKEN, etc.)
            if (!$tolerateUserErrors) {
                foreach ((array) ($json['data'] ?? []) as $payload) {
                    if (is_array($payload) && !empty($payload['userErrors'])) {
                        throw new \RuntimeException('Shopify userError: '.json_encode($payload['userErrors']));
                    }
                }
            }

            return $json['data'] ?? [];
        }
    }

    protected function backoff(int $attempt, int $retryAfterSeconds = 0): int
    {
        if ($retryAfterSeconds > 0) {
            return $retryAfterSeconds * 1_000_000;
        }

        return (int) (250_000 * (2 ** ($attempt - 1))); // 250ms, 500ms, 1s...
    }

    /**
     * The header value for one request: the stored token, renewed first if it is close
     * to expiry. An empty string is allowed to reach the API — a 401 is then read as
     * "this install needs OAuth", which is the truth, rather than the request being
     * skipped and a stale install reported as working.
     */
    protected function accessToken(): string
    {
        return (new TokenVault($this->shop))->token();
    }

    /**
     * Exchange an OAuth code for an offline access token (static — no shop row yet).
     *
     * `expiring=1` is the whole ballgame for a public app: without it Shopify hands
     * back the non-expiring token that the GraphQL Admin API now refuses, so the install
     * looks successful and then fails on its first real call — which is a far worse
     * support ticket than a refused install screen. The response carries the refresh
     * token and both lifetimes; AuthController saves all of it through
     * TokenVault::store(), because an install and a rotation must agree on what a token
     * response contains.
     */
    public static function exchangeCode(string $shopDomain, string $code): array
    {
        $response = Http::timeout(20)->asForm()->post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id'     => (string) config('shopify.api_key'),
            'client_secret' => (string) config('shopify.api_secret'),
            'code'          => $code,
            'expiring'      => 1,
        ]);

        if (!$response->ok() || empty($response->json('access_token'))) {
            throw new \RuntimeException('Token exchange failed for '.$shopDomain.': '.$response->body());
        }

        return $response->json();
    }
}
