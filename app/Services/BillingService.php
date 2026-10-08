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
 * Two modes, and they must not be mixed (config/shopify.php → billing.mode):
 *   shopify — plans and prices live in the Partner Dashboard. We neither create
 *             charges nor show our own numbers; we report what Shopify bills.
 *   api     — this class is the price table, and appSubscriptionCreate does the
 *             charging in the store's currency (or USD when we have no local price).
 *
 * Currencies: since API 2023-04, charges can be created in the merchant's
 * BILLING currency, and in `api` mode we price Indian stores directly in INR from
 * the config table, so the approval screen shows rupees with no FX fee. Stores whose
 * billing currency has no explicit price fall back to USD; if Shopify still rejects
 * the charge over currency, we retry once in USD (handles shops whose billing
 * currency differs from their shop currency).
 *
 * In `shopify` mode none of that applies and none of it is displayed: the amount on
 * the invoice is whatever the plan created in the Partner Dashboard says, in the
 * store's currency, and the only honest thing this app can show is the figure read
 * back from the subscription (readBilling). The config table is stripped from the
 * board payload in that mode so no screen can quote a price nobody will be charged.
 * During development set SHOPIFY_BILLING_TEST=true (fake-approvable charges).
 */
class BillingService
{
    public function __construct(protected Shop $shop) {}

    /**
     * True when the Partner Dashboard owns the prices (Shopify App Pricing).
     *
     * Deliberately phrased as "unless the developer said `api`": the failure this guards is
     * quoting a price nobody will be charged, and a stale config cache (or a typo in
     * SHOPIFY_BILLING_MODE) must not resurrect the app's own price table. Anything that is
     * not explicitly `api` leaves the money with Shopify.
     */
    public static function shopifyManaged(): bool
    {
        return config('shopify.billing.mode', 'shopify') !== 'api';
    }

    /**
     * The one sentence both endpoints and both mutations use when Shopify owns the billing.
     *
     * It names the button the merchant is looking at rather than a path through five menu
     * entries, because 1.2.3 is judged on whether a plan change is possible from inside the
     * app at all: "click the plan you want, Shopify's own page opens" has to be true.
     */
    public static function managedMessage(): string
    {
        return 'Plans, prices and billing for this app are managed by Shopify, so this app neither charges, cancels, nor quotes a price. '
            .'Use the plan button on this page — it opens Shopify\'s own plan page, where you can move up, move down or cancel, '
            .'and the change lands on your Shopify invoice.';
    }

    /**
     * Shopify's hosted plan selection page for THIS store.
     *
     * `https://admin.shopify.com/store/{store}/charges/{app}/pricing_plans` — documented
     * under Shopify App Pricing, and the page lists every plan including Free, so the same
     * link answers "upgrade", "downgrade" and "cancel" without this app touching money.
     *
     * Empty string means we cannot build it (no SHOPIFY_APP_HANDLE yet), which callers must
     * treat as "say where to go in words", never as "the button works".
     */
    public static function plansUrl(?Shop $shop = null): string
    {
        $configured = rtrim((string) config('shopify.billing.plans_url', ''), '/');

        if ($configured !== '') {
            return $configured;
        }

        $appHandle = strtolower(trim((string) config('shopify.billing.app_handle', '')));

        if ($appHandle === '' || $shop === null || trim((string) $shop->handle) === '') {
            return '';
        }

        return $shop->adminBaseUrl().'/charges/'.$appHandle.'/pricing_plans';
    }

    /** True when the app can send a merchant to Shopify's plan page for this store. */
    public static function canPickPlans(?Shop $shop = null): bool
    {
        return static::plansUrl($shop) !== '';
    }

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
        abort_if(static::shopifyManaged(), 422, static::managedMessage());

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

        // replacementBehavior is what makes an UPGRADE an upgrade: without it a merchant who
        // already pays for a plan creates a second subscription next to the first (or is told to
        // contact support, which is rejection 1.2.3). APPLY_IMMEDIATELY cancels the current
        // subscription when this one is approved and Shopify prorates the difference itself.
        $mutation = <<<'GQL'
        mutation AppSubscriptionCreate($name: String!, $returnUrl: URL!, $trialDays: Int, $test: Boolean, $amount: Decimal!, $currency: CurrencyCode!) {
          appSubscriptionCreate(
            name: $name,
            returnUrl: $returnUrl,
            replacementBehavior: APPLY_IMMEDIATELY,
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
            $this->shop->setSetting('billing', ['available' => true, 'subscribed' => false, 'read_at' => now()->toIso8601String()]);
            $this->shop->save();

            return ['plan' => $this->shop->plan, 'active' => false, 'billing' => $this->shop->setting('billing')];
        }

        $planKey = collect(config('shopify.plans'))
            ->filter(fn ($p, $key) => static::isPaidPlan($key))
            ->keys()
            ->first(fn ($key) => str_contains($active['name'], config("shopify.plans.{$key}.name")))
            ?: 'starter';

        $this->shop->forceFill(['plan' => $planKey, 'charge_id' => $active['id']])->save();

        // Remember what Shopify charges, and say so if our price table (used in `api`
        // mode, and by nothing at all here) disagrees. The log line is for the developer
        // who edits one and not the other; the merchant just sees Shopify's number.
        $bill = $this->readBilling();
        $this->shop->setSetting('billing', $bill + ['plan_key' => $planKey]);
        $this->shop->save();

        $ours = static::resolvePrice($planKey, (string) ($bill['currency'] ?? ($this->shop->currency ?: 'USD')));
        if ($bill['available'] && ($bill['subscribed'] ?? false) && $bill['amount'] !== null
            && abs((float) $ours[0] - (float) $bill['amount']) > 0.009) {
            Log::info('Billing: Shopify plan price differs from config/shopify.php plans', [
                'shop'            => $this->shop->domain,
                'plan'            => $planKey,
                'shopify'         => $bill['amount'].' '.$bill['currency'],
                'config'          => $ours[0].' '.$ours[1],
                'managed_by'      => static::shopifyManaged() ? 'shopify (config is display-only)' : 'app',
            ]);
        }

        return ['plan' => $planKey, 'active' => true, 'billing' => $bill, 'managed_by' => static::shopifyManaged() ? 'shopify' : 'app'];
    }

    /**
     * What Shopify says this store is billed, in the currency Shopify uses. This is the
     * only price the Plan tab may present when the Dashboard owns the plans: the store's
     * currency, the amount off the subscription, and the renewal date — never our config's
     * number with the store's currency symbol glued onto it.
     *
     * Tolerant by design: a failed read must not turn the Plan tab into an error screen, so
     * callers get ['available' => false] and fall back to "we could not read it".
     */
    public function readBilling(): array
    {
        $query = <<<'GQL'
        {
          currentAppInstallation {
            activeSubscriptions {
              id
              name
              status
              test
              trialDays
              createdAt
              currentPeriodEnd
              lineItems {
                plan {
                  pricingDetails {
                    ... on AppRecurringPricing {
                      price { amount currencyCode }
                      interval
                    }
                  }
                }
              }
            }
          }
        }
        GQL;

        try {
            $data = (new ShopifyClient($this->shop))->graphql($query);
        } catch (\Throwable $e) {
            Log::info('Billing: could not read the subscription from Shopify', [
                'shop' => $this->shop->domain, 'err' => $e->getMessage(),
            ]);

            return ['available' => false];
        }

        $subs = $data['currentAppInstallation']['activeSubscriptions'] ?? [];
        $active = collect($subs)->firstWhere('status', 'ACTIVE') ?? collect($subs)->first();

        if (!$active) {
            return ['available' => true, 'subscribed' => false, 'test' => false];
        }

        $price = collect($active['lineItems'] ?? [])
            ->map(fn ($li) => $li['plan']['pricingDetails'] ?? null)
            ->filter(fn ($p) => is_array($p) && isset($p['price']['amount']))
            ->first();

        return [
            'available'    => true,
            'subscribed'   => true,
            'name'         => (string) ($active['name'] ?? ''),
            'status'       => (string) ($active['status'] ?? ''),
            'test'         => (bool) ($active['test'] ?? false),
            'trial_days'   => (int) ($active['trialDays'] ?? 0),
            'started_at'   => $active['createdAt'] ?? null,
            'renews_at'    => $active['currentPeriodEnd'] ?? null,
            'amount'       => isset($price['price']['amount']) ? (float) $price['price']['amount'] : null,
            'currency'     => $price['price']['currencyCode'] ?? null,
            'interval'     => $price['interval'] ?? null,
            'read_at'      => now()->toIso8601String(),
        ];
    }

    /** Cancel the paid plan (back to Free). */
    public function cancelSubscription(): bool
    {
        abort_if(static::shopifyManaged(), 422, static::managedMessage());

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
