<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Shopify Billing API (GraphQL appSubscriptionCreate).
 *
 * Public apps MUST charge through Shopify's Billing API — third-party
 * payment links for the app subscription are a review rejection.
 *
 * Currencies: since API 2023-04, charges can be created in the merchant's
 * BILLING currency. We price Indian stores directly in INR (₹499/₹999 — the
 * approval screen shows rupees, zero FX fee, predictable price). Stores whose
 * billing currency has no explicit price fall back to USD; if Shopify still
 * rejects the charge over currency, we retry once in USD (handles shops
 * whose billing currency differs from their shop currency).
 * During development set SHOPIFY_BILLING_TEST=true (fake-approvable charges).
 */
class BillingService
{
    public function __construct(protected Shop $shop) {}

    public function isDevMode(): bool
    {
        return (bool) env('SHOPIFY_BILLING_TEST', true);
    }

    /** true when the plan costs money (has any price entry). */
    public static function isPaidPlan(?string $planKey): bool
    {
        return !empty(config("shopify.plans.{$planKey}.prices"));
    }

    /**
     * Price for a shop's billing currency: exact match if configured,
     * otherwise the fallback (USD). Returns [amount, currencyCode].
     */
    public static function resolvePrice(string $planKey, string $shopCurrency): array
    {
        $prices   = config("shopify.plans.{$planKey}.prices", []);
        $fallback = (string) config('shopify.billing_fallback_currency', 'USD');

        if ($shopCurrency !== '' && isset($prices[$shopCurrency])) {
            return [$prices[$shopCurrency], $shopCurrency];
        }

        return [$prices[$fallback] ?? (float) reset($prices), $prices[$fallback] ? $fallback : (string) array_key_first($prices)];
    }

    /** Create a subscription charge, returns the Shopify confirmation URL. */
    public function createSubscription(string $planKey): string
    {
        abort_unless(static::isPaidPlan($planKey), 422, 'Unknown or free plan');

        $shopCurrency = $this->resolveShopCurrency();
        [$amount, $currencyCode] = static::resolvePrice($planKey, $shopCurrency);

        try {
            return $this->runCreateMutation($planKey, $amount, $currencyCode);
        } catch (\Throwable $e) {
            // Shop currency ≠ billing currency (rare) → retry once in USD.
            $fallback = (string) config('shopify.billing_fallback_currency', 'USD');
            if ($currencyCode === $fallback || !str_contains(strtoupper($e->getMessage()), 'CURREN')) {
                throw $e;
            }
            Log::info('Billing: retrying in fallback currency', ['shop' => $this->shop->domain, 'tried' => $currencyCode]);
            [$amount, $currencyCode] = static::resolvePrice($planKey, $fallback);

            return $this->runCreateMutation($planKey, $amount, $currencyCode);
        }
    }

    protected function runCreateMutation(string $planKey, float $amount, string $currencyCode): string
    {
        $plan = config("shopify.plans.{$planKey}");

        $returnUrl = rtrim((string) config('shopify.app_url'), '/').'/billing/callback?shop='
            .urlencode($this->shop->domain).'&plan='.urlencode($planKey);

        $mutation = <<<'GQL'
        mutation AppSubscriptionCreate($name: String!, $returnUrl: URL!, $trialDays: Int, $test: Boolean, $amount: Decimal!, $currency: CurrencyCode!) {
          appSubscriptionCreate(
            name: $name,
            returnUrl: $returnUrl,
            trialDays: $trialDays,
            test: $test,
            lineItems: [{
              plan: {
                appRecurringPricingDetails: {
                  price: { amount: $amount, currencyCode: $currency }
                  interval: EVERY_30_DAYS
                }
              }
            }]
          ) {
            confirmationUrl
            appSubscription { id status }
            userErrors { field message }
          }
        }
        GQL;

        $data = (new ShopifyClient($this->shop))->graphql($mutation, [
            'name'      => config('app.name').' '.$plan['name'],
            'returnUrl' => $returnUrl,
            'trialDays' => (int) $plan['trial_days'],
            'test'      => $this->isDevMode(),
            'amount'    => $amount,
            'currency'  => $currencyCode,
        ]);

        return $data['appSubscriptionCreate']['confirmationUrl']
            ?? throw new \RuntimeException('No confirmationUrl from Shopify');
    }

    /** Merchant's billing/shop currency, cached on the shop row. */
    protected function resolveShopCurrency(): string
    {
        if (!empty($this->shop->currency)) {
            return $this->shop->currency;
        }

        try {
            $data     = (new ShopifyClient($this->shop))->graphql('{ shop { currencyCode } }');
            $currency = $data['shop']['currencyCode'] ?? null;
            if (is_string($currency) && strlen($currency) === 3) {
                $this->shop->forceFill(['currency' => $currency])->save();

                return $currency;
            }
        } catch (\Throwable $e) {
            Log::info('Billing: currency lookup failed, using fallback', ['shop' => $this->shop->domain]);
        }

        return (string) config('shopify.billing_fallback_currency', 'USD');
    }

    /**
     * After the merchant approves/declines on Shopify's hosted page, confirm
     * the ACTIVE subscription server-side and persist the plan. Never trust
     * the return URL params alone.
     */
    public function syncActiveSubscription(): array
    {
        $query = <<<'GQL'
        {
          currentAppInstallation {
            activeSubscriptions { id name status trialDays createdAt test }
          }
        }
        GQL;

        $data  = (new ShopifyClient($this->shop))->graphql($query);
        $subs  = $data['currentAppInstallation']['activeSubscriptions'] ?? [];
        $active = collect($subs)->firstWhere('status', 'ACTIVE');

        if (!$active) {
            $this->shop->forceFill(['plan' => config('shopify.default_plan'), 'charge_id' => null])->save();

            return ['plan' => $this->shop->plan, 'active' => false];
        }

        $planKey = collect(config('shopify.plans'))
            ->filter(fn ($p, $key) => static::isPaidPlan($key))
            ->keys()
            ->first(fn ($key) => str_contains($active['name'], config("shopify.plans.{$key}.name")))
            ?: 'starter';

        $this->shop->forceFill(['plan' => $planKey, 'charge_id' => $active['id']])->save();

        return ['plan' => $planKey, 'active' => true];
    }

    /** Cancel the paid plan (back to Free). */
    public function cancelSubscription(): bool
    {
        if (!$this->shop->charge_id) {
            return true;
        }

        $mutation = <<<'GQL'
        mutation AppSubscriptionCancel($id: ID!) {
          appSubscriptionCancel(id: $id) {
            appSubscription { status }
            userErrors { field message }
          }
        }
        GQL;

        (new ShopifyClient($this->shop))->graphql($mutation, [
            'id' => $this->shop->charge_id,
        ], tolerateUserErrors: true);

        $this->shop->forceFill(['plan' => config('shopify.default_plan'), 'charge_id' => null])->save();

        return true;
    }
}
