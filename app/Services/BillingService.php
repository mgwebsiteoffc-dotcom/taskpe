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

        // Shopify's own answer first: it is the handle the admin actually routes on. The config
        // value is the fallback for a store that has not been asked yet, and it is exactly the one
        // that can be a typo away from sending a merchant to the Apps list.
        $appHandle = $shop !== null
            ? $shop->shopifyAppHandle()
            : strtolower(trim((string) config('shopify.billing.app_handle', '')));

        if ($appHandle === '') {
            $appHandle = strtolower(trim((string) config('shopify.billing.app_handle', '')));
        }

        if ($appHandle === '' || $shop === null) {
            return '';
        }

        // `myshopify` style needs only the domain, which is never wrong about itself; the admin
        // form needs the store slug, and an empty one builds a URL Shopify will not resolve.
        if (strtolower((string) config('shopify.billing.plans_url_style', 'admin')) === 'myshopify') {
            return trim((string) $shop->domain) === ''
                ? ''
                : $shop->myshopifyAdminUrl().'/charges/'.$appHandle.'/pricing_plans';
        }

        if ($shop->adminHandle() === '') {
            return '';
        }

        return $shop->adminBaseUrl().'/charges/'.$appHandle.'/pricing_plans';
    }

    /**
     * What Shopify says this installation's app handle is.
     *
     * One field, and it is the one the whole plan link hangs on: the admin page lives at
     * `/charges/{app handle}/pricing_plans`, and a wrong handle is not a 404 the merchant can see
     * the reason for — Shopify answers by opening its Apps list, which reads as the button doing
     * nothing. Reading it from the store instead of trusting an `.env` line turns "check the config"
     * into a solved problem.
     *
     * Cached for a day because it is read on the billing paths, not on every page load, and a
     * rename is a deploy-shaped event. `--force` is for the command, where a human wants the answer
     * now. Failure is silent by design: the config value keeps working, and the caller is told the
     * link is unverified rather than being denied it.
     */
    public function reportedAppHandle(bool $force = false): ?string
    {
        $check = (array) $this->shop->setting('billing.app_handle_check', []);
        $held  = (string) ($check['handle'] ?? '');
        $fresh = $held !== '' && strtotime((string) ($check['checked_at'] ?? '')) > time() - 86400;

        if (!$force && $fresh) {
            return $held;
        }

        // Assigned first, on purpose: the closing identifier has to sit on its own line with only a
        // semicolon after it, which is how every other query in this class is written.
        $query = <<<'GQL'
        {
          currentAppInstallation {
            app { handle }
          }
        }
        GQL;

        try {
            $data = (new ShopifyClient($this->shop))->graphql($query);
        } catch (\Throwable $e) {
            Log::info('Billing: could not read the app handle from Shopify', [
                'shop' => $this->shop->domain, 'err' => $e->getMessage(),
            ]);

            return $held !== '' ? $held : null;      // a stale answer beats no answer, and beats breaking the page
        }

        $handle = strtolower(trim((string) data_get($data, 'currentAppInstallation.app.handle', '')));

        if ($handle === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $handle)) {
            return $held !== '' ? $held : null;
        }

        $configured = strtolower(trim((string) config('shopify.billing.app_handle', '')));

        if ($configured !== '' && $configured !== $handle) {
            // The bug this whole method exists for, said once in the log instead of discovered by
            // a merchant whose plan button kept landing them on the Apps list.
            Log::warning('SHOPIFY_APP_HANDLE disagrees with the handle Shopify reports; using Shopify\'s', [
                'shop'      => $this->shop->domain,
                'env_value' => $configured,
                'reported'  => $handle,
            ]);
        }

        $this->shop->setSetting('billing.app_handle_check', [
            'handle'     => $handle,
            'checked_at' => now()->toIso8601String(),
            'domain'     => $this->shop->domain,
        ]);
        $this->shop->save();

        return $handle;
    }

    /**
     * The handle behind the plan link, and whether it can be trusted. `source` is what the Plan tab
     * and `taskpe:plans` print, because "unverified" is actionable and "unknown" is not.
     *
     * @return array{handle:string,source:string,verified:bool,matches_config:bool}
     */
    public function appHandleStatus(): array
    {
        $reported   = $this->shop->shopifyAppHandle();
        $configured = strtolower(trim((string) config('shopify.billing.app_handle', '')));

        if ($reported !== '') {
            return [
                'handle'         => $reported,
                'source'         => 'shopify',
                'verified'       => true,
                'matches_config' => $configured === '' || $configured === $reported,
            ];
        }

        return [
            'handle'         => $configured,
            'source'         => $configured !== '' ? 'env (not confirmed with Shopify)' : 'none',
            'verified'       => false,
            'matches_config' => true,
        ];
    }

    /** True when the app can send a merchant to Shopify's plan page for this store. */
    public static function canPickPlans(?Shop $shop = null): bool
    {
        return static::plansUrl($shop) !== '';
    }

    /**
     * Shopify's handle for one of its App Pricing plans.
     *
     * Two sources, in order of trust: the handle Shopify itself told us for THIS store (it appends
     * `?plan_handle=` to the return redirect after a plan change, so any store that has switched
     * once is never guessed about again), then the configured one. A wrong handle is a 404 on the
     * one page where a merchant had decided to pay, so nothing here infers one — no handle, no deep
     * link, and the plan list is a perfectly good answer.
     */
    public function planHandleFor(string $planKey): string
    {
        $seen = (array) $this->shop->setting('billing.plan_handles', []);
        $own  = trim((string) ($seen[$planKey] ?? ''));

        if ($own !== '') {
            return $own;
        }

        return trim((string) config("shopify.plans.{$planKey}.plan_handle", ''));
    }

    /**
     * Persist what Shopify just told us, so the next switch goes straight to that plan's page.
     * Idempotent and cheap: a board load that changes nothing performs no write.
     */
    public function rememberPlanHandle(?string $planKey, string $handle): void
    {
        $planKey = trim((string) $planKey);
        $handle  = trim($handle);

        if ($planKey === '' || $handle === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $handle)) {
            return;     // the handle goes into a URL path; anything else is not a handle
        }

        $seen = (array) $this->shop->setting('billing.plan_handles', []);

        if (($seen[$planKey] ?? null) === $handle) {
            return;
        }

        $seen[$planKey] = $handle;
        $this->shop->setSetting('billing.plan_handles', $seen);
        $this->shop->save();
    }

    /**
     * Where "switch to this plan" takes the merchant when Shopify owns the money.
     *
     * `shopify-plan`   Shopify's approval page, already showing that plan — one decision, not two.
     * `shopify-picker` Shopify's plan list (the documented, always-available page).
     * `none`           nothing to open: this install cannot build an admin URL. The Plan tab then
     *                  names the setting instead of rendering a button that goes nowhere.
     */
    public function planLink(string $planKey): array
    {
        $picker = static::plansUrl($this->shop);
        $handle = $planKey === '' ? '' : $this->planHandleFor($planKey);

        if ($handle !== '' && $picker !== '') {
            $deep = preg_replace('#/pricing_plans/?$#', '/plans/'.rawurlencode($handle), $picker, 1);

            if ($deep !== $picker && is_string($deep)) {
                return ['kind' => 'shopify-plan', 'url' => $deep];
            }
        }

        return ['kind' => $picker !== '' ? 'shopify-picker' : 'none', 'url' => $picker];
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

        abort_unless(
            static::isPaidPlan($planKey), 422,
            'There is no charge to create for that plan. Free is not a charge — to move a store to '            .'Free, cancel the current one (Plan tab → Move to Free).'
        );

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
    public function syncActiveSubscription(?string $planHandle = null): array
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

        // Which of OUR plans is this? Shopify only tells us a name and, on the way back from its
        // plan page, a handle. The handle is the reliable one — it is the string Shopify itself
        // issued, and this app has remembered it for any store that changed plan once. Name
        // matching is the fallback, and a *silent* fallback is how "the plan did not change" gets
        // reported: a Dashboard plan called "Scale" would otherwise be written off as Starter and
        // the merchant would pay for Growth while the board kept Starter limits.
        [$planKey, $mappedBy] = $this->resolvePlanKey((string) ($active['name'] ?? ''), $planHandle);

        $this->shop->forceFill(['plan' => $planKey, 'charge_id' => $active['id']])->save();

        // Remember what Shopify charges, and say so if our price table (used in `api`
        // mode, and by nothing at all here) disagrees. The log line is for the developer
        // who edits one and not the other; the merchant just sees Shopify's number.
        $bill = $this->readBilling();
        $this->shop->setSetting('billing', $bill + [
            'plan_key'          => $planKey,
            'mapped_by'         => $mappedBy,
            'shopify_plan_name' => (string) ($active['name'] ?? ''),
            'shopify_handle'    => (string) ($planHandle ?? ''),
        ]);
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

        if ($mappedBy === 'fallback') {
            Log::warning('Billing: Shopify plan name matched no plan in config/shopify.php; using the lowest paid plan', [
                'shop'         => $this->shop->domain,
                'shopify_name' => (string) ($active['name'] ?? ''),
                'plan_key'     => $planKey,
                'fix'          => 'name the Dashboard plan so it contains a plan name from config, '
                    .'or set TASKPE_PLAN_*_HANDLE / let the app learn it from a plan change',
            ]);
        }

        return [
            'plan'       => $planKey,
            'active'     => true,
            'mapped_by'  => $mappedBy,
            'billing'    => $bill,
            'managed_by' => static::shopifyManaged() ? 'shopify' : 'app',
        ];
    }

    /**
     * Which entry of `config/shopify.php → plans` a Shopify subscription belongs to.
     *
     * handle (what Shopify issued) → name (what a human typed in the Dashboard) → fallback.
     *
     * @return array{0:string,1:string}  plan key, and how it was decided
     */
    public function resolvePlanKey(string $shopifyName, ?string $planHandle = null): array
    {
        $paid = collect(config('shopify.plans'))
            ->filter(fn ($p, $key) => static::isPaidPlan($key))
            ->keys();

        $planHandle = trim((string) $planHandle);

        if ($planHandle !== '') {
            foreach ((array) $this->shop->setting('billing.plan_handles', []) as $key => $known) {
                if ((string) $known === $planHandle && $paid->contains($key)) {
                    return [(string) $key, 'handle'];
                }
            }
        }

        foreach ($paid as $key) {
            $label = trim((string) config("shopify.plans.{$key}.name"));

            if ($label !== '' && mb_stripos($shopifyName, $label) !== false) {
                return [(string) $key, 'name'];
            }
        }

        // Last resort, and never a quiet one: the caller logs it, the Plan tab can say so, and
        // `taskpe:plans` prints the pair it ended up believing.
        return [(string) ($paid->first() ?? config('shopify.default_plan')), 'fallback'];
    }

    /**
     * The price table the Plan tab prints, with an honesty marker on every number.
     *
     * Three kinds of value, and they must never be confused on screen:
     *
     *   `billed`  Shopify's own figure for the plan this store is actually on — read back from
     *             the subscription. This is the only number that may be presented as "what you pay",
     *             and it is what the current plan's card shows (the bug this whole area was
     *             rebuilt around was a config ₹499 sitting beside a $5.99 invoice).
     *   `list`    this app's table, shown on the plans the store is NOT on so the page is
     *             readable — labelled as a list price, with `exact` false when we have no price
     *             in the store's billing currency, because "US$5.99, converted by Shopify at
     *             checkout" is a different promise from "₹499".
     *   `drift`   the two disagree. The app cannot fix that (Shopify's number wins on the
     *             invoice), so it says so out loud and names where to change it, and
     *             `php artisan taskpe:plans` does the same for every store at once.
     */
    public function planCatalog(): array
    {
        $currency = (string) ($this->shop->currency ?: config('shopify.billing_fallback_currency', 'USD'));
        $bill     = (array) $this->shop->setting('billing', []);
        $readback = ($bill['available'] ?? null) !== false && ($bill['subscribed'] ?? false);

        $out = [];

        foreach ((array) config('shopify.plans') as $key => $plan) {
            $prices = (array) ($plan['prices'] ?? []);
            $entry  = [
                'name'       => (string) ($plan['name'] ?? $key),
                'trial_days' => (int) ($plan['trial_days'] ?? 0),
                'list'       => null,
                'exact'      => false,
                // 'free_plan' is not the same fact as 'list => null': one is a plan that costs
                // nothing, the other is this app having no number for it. Printed as "Free" by
                // accident, the second one is a false promise about money.
                'free_plan'  => $prices === [],
            ];

            if ($prices !== []) {
                [$amount, $code] = static::resolvePrice($key, $currency);

                $entry['list']  = round((float) $amount, 2);
                $entry['code']  = (string) $code;
                $entry['exact'] = isset($prices[$currency]);
            }

            // Only the plan the store is actually on can be confirmed against the invoice.
            if ($readback && $key === $this->shop->plan && isset($bill['amount']) && $bill['amount'] !== null) {
                $entry['billed'] = [
                    'amount'    => round((float) $bill['amount'], 2),
                    'currency'  => (string) ($bill['currency'] ?? $currency),
                    'interval'  => (string) ($bill['interval'] ?? 'EVERY_30_DAYS'),
                    'renews_at' => $bill['renews_at'] ?? null,
                    'test'      => (bool) ($bill['test'] ?? false),
                    'trial'     => (int) ($bill['trial_days'] ?? 0),
                ];

                if ($entry['list'] !== null && abs((float) $entry['list'] - (float) $bill['amount']) > 0.009) {
                    $entry['drift'] = [
                        'list'    => $entry['list'].' '.$entry['code'],
                        'shopify' => $bill['amount'].' '.($bill['currency'] ?? $currency),
                    ];
                }
            }

            // How a switch to THIS plan happens, decided server-side: the SPA must not guess
            // whether it is creating a charge, opening Shopify's approval page, opening the plan
            // list, or telling the merchant nothing is possible from here. `cancel` is the
            // Billing-API road to Free, because that one the app really can do itself.
            $entry['switch'] = static::shopifyManaged()
                ? $this->planLink($key)
                : ['kind' => $prices === [] ? 'cancel' : 'charge', 'url' => ''];

            $out[$key] = $entry;
        }

        return $out;
    }

    /**
     * What Shopify says this store is billed, in the currency Shopify uses. This is the
     * only figure the Plan tab may describe as what the merchant pays, in either billing
     * mode: the store's currency, the amount off the subscription, and the renewal date —
     * never our config's number with the store's currency symbol glued onto it. (The list
     * prices in `planCatalog()` do appear beside it, labelled as list prices.)
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
