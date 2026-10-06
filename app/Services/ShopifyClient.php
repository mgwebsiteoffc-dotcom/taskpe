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

        while (true) {
            $attempt++;
            $response = Http::timeout(20)
                ->withHeaders(['X-Shopify-Access-Token' => $this->shop->access_token])
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

            if (!empty($json['errors'])) {
                Log::warning('Shopify GraphQL errors', ['shop' => $this->shop->domain, 'errors' => $json['errors']]);
                throw new \RuntimeException('Shopify GraphQL error: '.json_encode($json['errors']));
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

    /** Exchange an OAuth code for an offline access token (static — no shop row yet). */
    public static function exchangeCode(string $shopDomain, string $code): array
    {
        $response = Http::timeout(20)->post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id'     => config('shopify.api_key'),
            'client_secret' => config('shopify.api_secret'),
            'code'          => $code,
        ]);

        if (!$response->ok() || empty($response->json('access_token'))) {
            throw new \RuntimeException('Token exchange failed for '.$shopDomain.': '.$response->body());
        }

        return $response->json();
    }
}
