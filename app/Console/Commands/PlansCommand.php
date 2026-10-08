<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\BillingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The plan prices, and whether they still agree with Shopify.
 *
 * The Plan tab prints two kinds of number, and only one of them describes an invoice:
 *
 *   list price   config/shopify.php → plans. What this app says a plan costs, shown on the plans
 *                a store is NOT on so the choices are comparable. In `api` mode it is also what
 *                the merchant is charged; in `shopify` mode the Partner Dashboard wins and this
 *                table is display-only.
 *   billed       read back from the store's own subscription. This is the invoice.
 *
 * Those two drift apart the moment someone edits one and not the other, and the merchant finds out
 * first. This command says it for every store at once, and names the exact line to change.
 *
 *   php artisan taskpe:plans                          the table, then every store
 *   php artisan taskpe:plans --live                   ask Shopify again first (one request per store)
 *   php artisan taskpe:plans --links                  what each plan button will actually open,
 *                                                     which app handle it was built from, and whether
 *                                                     Shopify has confirmed that handle
 *   php artisan taskpe:plans --links --verify           ...after asking Shopify, per store
 *   php artisan taskpe:plans house-of-indha.myshopify.com
 *
 * `--links` exists because of a specific bug report: "the plan doesn't change, it just sends me to
 * the app list". Shopify does not answer a wrong /charges/{app}/... address with an error the merchant
 * can read — it opens its Apps list, which looks like a button that was ignored. The one input to
 * that URL is the app handle, so the command prints the URL, where the handle came from, and whether
 * it came from Shopify itself.
 *
 * The exit code is non-zero while any list price disagrees with what Shopify bills, so a deploy
 * script can fail on it. A visible price is a number somebody now has to maintain — that is the
 * whole cost of showing one, and it is cheaper to check it here than to be told about it in review.
 */
class PlansCommand extends Command
{
    protected $signature = 'taskpe:plans
                            {shop? : myshopify.com domain (default: every shop)}
                            {--live : read each store\'s subscription from Shopify again before comparing}
                            {--links : print the plan-page URLs and where each app handle came from}
                            {--verify : with --links, ask Shopify for its own handle first}';

    protected $description = 'Show the plan price table, what Shopify bills each store, and where a plan button actually leads';

    public function handle(): int
    {
        $managed = BillingService::shopifyManaged();

        $this->line('');
        $this->line($managed
            ? '<comment>Shopify prices these plans</comment> — Partner Dashboard → your app → App pricing.'
            : '<comment>This app prices these plans</comment> — a merchant is charged the amount below.');
        $this->line('config/shopify.php → plans is what the Plan tab prints as the LIST price. '
            .($managed ? 'It changes nothing about a bill; it only has to be true.' : 'It is also what the Billing API charges.'));
        $this->line('');

        $this->table(['plan', 'shown as', 'list price', 'trial', 'effect of this table'], $this->configRows());

        $shops = $this->shops();

        if ($shops->isEmpty()) {
            $this->line('No shop rows yet — one appears as soon as a store finishes installing the app,');
            $this->line('so there is nothing to compare until then.');

            return self::SUCCESS;
        }

        $rows  = [];
        $stale = 0;

        foreach ($shops as $shop) {
            if ($this->option('live')) {
                try {
                    (new BillingService($shop))->syncActiveSubscription();
                } catch (\Throwable $e) {
                    $this->line('  <comment>could not re-read '.$shop->domain.':</comment> '.$e->getMessage());
                }
            }

            $catalog = (new BillingService($shop))->planCatalog();
            $current = $catalog[$shop->plan] ?? null;
            $list    = $current && isset($current['list'])
                ? $this->money((float) $current['list'], (string) ($current['code'] ?? 'USD'))
                : ($current && ($current['free_plan'] ?? false) ? 'free' : '—');

            if (!$current || !isset($current['billed'])) {
                $rows[] = [$shop->domain, $shop->plan, $list, 'nothing billed', $this->unverifiedNote($shop)];

                continue;
            }

            $billed = $this->money((float) $current['billed']['amount'], (string) $current['billed']['currency']);

            if (!isset($current['drift'])) {
                $rows[] = [$shop->domain, $shop->plan, $list, $billed, 'matches'];

                continue;
            }

            $stale++;
            $rows[] = [$shop->domain, $shop->plan, $list, $billed, 'STALE — fix shown above'];
            $this->line('  <comment>'.$shop->domain.' · '.$shop->plan.':</comment> Shopify bills '.$billed
                .' but config/shopify.php lists '.$current['drift']['list'].'.');
            $this->line('    fix the app\'s number: plans.'.$shop->plan.'.prices.'.$current['billed']['currency']
                .' = '.rtrim(rtrim((string) $current['billed']['amount'], '0'), '.')
                .'   (then: php artisan config:clear)');
            $this->line('    or the other way: set the same amount in Partner Dashboard → App pricing, which is what the merchant pays either way.');
        }

        $this->line('');
        $this->table(['store', 'on plan', 'list price here', 'Shopify bills', 'verdict'], $rows);

        if (!$this->option('live')) {
            $this->line('The "Shopify bills" column is the last read the app made (the merchant opening the Plan tab, or a sync).');
            $this->line('php artisan taskpe:plans --live asks Shopify again for every store before comparing.');
        }

        if ($stale === 0) {
            $this->line('Every list price agrees with the invoice it sits beside. Nothing to change.');
        } else {
            $this->line('<comment>'.$stale.' store(s) are shown a price that disagrees with Shopify.</comment>');
            $this->line('The merchant is billed Shopify\'s amount either way — the app is the one that is wrong, and it is the page they read.');
        }

        if ($managed && $shops->contains(fn (Shop $s) => BillingService::plansUrl($s) === '')) {
            $this->line('');
            $this->line('<comment>A store above has no link to Shopify\'s plan page.</comment> The Plan tab can show a price');
            $this->line('but cannot take anyone to the page that changes it, which is exactly the complaint in review.');
            $this->line('Set SHOPIFY_APP_HANDLE (the handle in admin.shopify.com/store/<handle>) and shops.handle for that store,');
            $this->line('or SHOPIFY_APP_PLANS_URL to a full URL — then: php artisan config:clear');
        }

        $this->roadReport($shops);
        $this->mappingReport($shops);

        $links = $this->option('links') ? $this->linksReport($shops) : self::SUCCESS;

        return $stale === 0 ? $links : self::FAILURE;
    }

    /**
     * Which billing road this install is on, and whether it can work at all.
     *
     * The line this exists for is Shopify's "There's no page at this address" on
     * /charges/{app}/pricing_plans. That page is not the app's to create: it exists only when the
     * Partner Dashboard has App Pricing ENABLED with at least one PLAN, and neither fact is visible
     * to the app through the Admin API. So instead of guessing, print what the app believes, what it
     * built, and the two answers that make the 404 go away.
     */
    protected function roadReport(Collection $shops): void
    {
        $managed = BillingService::shopifyManaged();

        $this->line('');
        $this->line('Billing road');
        $this->line('  mode: ' . ($managed
            ? 'shopify — Shopify App Pricing; the Dashboard prices the plans and this app only links to them'
            : 'api — this app creates the subscription with appSubscriptionCreate from its own price table'));

        $forced = config('shopify.billing.test_charges');

        if ($forced === null || $forced === '') {
            $this->line('  test charges: decided per store from shop.plan.partnerDevelopment, so a real');
            $this->line('  merchant is never handed a fake charge and nobody has to remember a flag.');
        } elseif ($forced) {
            $this->line('  test charges: <comment>FORCED ON</comment> by SHOPIFY_BILLING_TEST=true. Every charge this app');
            $this->line('  creates is a test charge — nobody pays, while the Plan tab still looks healthy.');
            $this->line('  Remove that line on a production host; it is the setting that quietly costs all revenue.');
        } else {
            $this->line('  test charges: forced off. A development store then cannot rehearse approval unless its');
            $this->line('  Dashboard plan is marked free to test; set SHOPIFY_BILLING_TEST=true there only.');
        }

        if (!$managed) {
            return;
        }

        $learned = 0;

        foreach ($shops as $shop) {
            $handles = (array) $shop->setting('billing.plan_handles', []);

            if ($handles !== []) {
                $learned++;
                $this->line('  ' . $shop->domain . ': plan handles Shopify itself told us — '
                    . collect($handles)->map(fn ($h, $k) => $k . '=' . $h)->join(', '));
            }
        }

        $this->line('  plan page: not verifiable from the app — the Dashboard creates it. Check, in order:');
        $this->line('    1. Partner Dashboard → your app → App pricing: the pricing model must be Shopify App');
        $this->line('       Pricing, not “Set up your own pricing”. That choice and SHOPIFY_BILLING_MODE disagreeing');
        $this->line('       IS this 404: the app links to a page the Dashboard never undertook to make.');
        $this->line('    2. At least one plan exists — Free should be one of them — each with the welcome link');
        $this->line('       /billing/callback. With no plans there is no page at that address to open.');
        $this->line('    The alternative that needs nothing in the Dashboard: SHOPIFY_BILLING_MODE=api, where');
        $this->line('    this app creates the charge itself, in the store’s billing currency.');

        if ($learned === 0) {
            $this->line('  deep links: no plan handles known yet, so a plan click opens the plan list. The app reads');
            $this->line('  each plan’s own handle from the store’s subscription (AppRecurringPricing.planHandle), so');
            $this->line('  after one plan change per store the next click goes straight to that plan’s approval page.');
        }
    }

    /**
     * What Shopify calls each store's plan, and how this app decided which of its own plans that is.
     *
     * `handle` and `name` are real matches. `fallback` means the Dashboard plan is named something
     * this app cannot recognise ("Scale", "Growth - monthly" with a config label of "TaskPe Growth")
     * and the store was therefore mapped to the cheapest paid plan: the merchant is billed correctly
     * and unlocked wrongly, which is precisely a "I paid and nothing changed" report. Fix it by
     * naming the Dashboard plan so it contains the config plan name, or by setting the plan handle.
     */
    protected function mappingReport(Collection $shops): void
    {
        $rows = [];

        foreach ($shops as $shop) {
            $bill = (array) $shop->setting('billing', []);

            if (!($bill['subscribed'] ?? false)) {
                continue;
            }

            $how = (string) ($bill['mapped_by'] ?? 'never synced');

            $rows[] = [
                $shop->domain,
                (string) ($bill['shopify_plan_name'] ?? '—') ?: '—',
                (string) ($bill['plan_key'] ?? $shop->plan),
                $how,
                $how === 'fallback'
                    ? 'rename the plan in Partner Dashboard, or set TASKPE_PLAN_*_HANDLE'
                    : 'fine',
            ];
        }

        if ($rows === []) {
            return;
        }

        $this->line('');
        $this->line('Plan mapping — what Shopify calls the subscription, and how this app matched it');
        $this->table(['store', 'Shopify plan name', 'matched to', 'by', 'action'], $rows);

        $fallbacks = collect($rows)->filter(fn ($r) => $r[3] === 'fallback')->count();

        if ($fallbacks > 0) {
            $this->line('<comment>'.$fallbacks.' store(s) are matched by fallback, not by name or handle.</comment> '
                .'They are billed by Shopify for whatever plan they bought, while this app applies the '
                .'cheapest paid plan\'s limits to them.');
        }
    }

    /**
     * What the plan buttons will do, per store — the answer to "it redirects to the app list".
     *
     * Two things can bounce a merchant to Shopify's Apps list instead of the plan page, and both
     * are visible from the shop row alone: an app handle that is not the one the admin routes on,
     * and a store slug that does not match the domain. `verified: no` means the handle came from
     * SHOPIFY_APP_HANDLE and Shopify has never confirmed it, which is the case worth checking.
     */
    protected function linksReport(Collection $shops): int
    {
        $this->line('');
        $this->line('Plan links — what each button opens (style: '
            .config('shopify.billing.plans_url_style', 'admin').')');

        $rows   = [];
        $broken = 0;

        foreach ($shops as $shop) {
            if ($this->option('verify')) {
                (new BillingService($shop))->reportedAppHandle(true);
                $shop->refresh();
            }

            $service = new BillingService($shop);
            $status  = $service->appHandleStatus();
            $picker  = BillingService::plansUrl($shop);

            $known = [];

            foreach ((array) config('shopify.plans') as $key => $plan) {
                $known[] = $key.':'.(($service->planHandleFor((string) $key) !== '') ? 'direct' : 'list');
            }

            if ($picker === '' || !$status['verified']) {
                $broken++;
            }

            $rows[] = [
                $shop->domain,
                $shop->adminHandle() ?: '<none — derived from the domain>',
                $status['handle'] ?: '<none>',
                $status['source'].($status['matches_config'] ? '' : ' (config disagrees!)'),
                $picker === '' ? 'NO LINK — buttons will explain, not open' : $picker,
                implode('  ', $known),
            ];
        }

        $this->table(['store', 'store slug', 'app handle', 'handle source', 'plan page it opens', 'per-plan deep link'], $rows);

        $this->line('');

        if ($broken === 0) {
            $this->line('Every store has a plan link built from a handle Shopify itself reported, so a plan click');
            $this->line('lands on the approval page. "list" next to a plan means its Shopify plan handle is not known');
            $this->line('yet — the merchant picks there and the app remembers it for next time; that is the plan list,');
            $this->line('not a failure.');
        } else {
            $this->line('<comment>'.$broken.' store(s) have a plan link that Shopify has not confirmed.</comment>');
            $this->line('Run this with --verify to ask Shopify for the real handle, and confirm App Pricing lists at');
            $this->line('least one plan (Partner Dashboard -> your app -> App pricing): with no plans there is no page');
            $this->line('at that address, and Shopify answers with its Apps list. If a link is right and the admin');
            $this->line('still opens its Apps list, the store slug is the suspect: set SHOPIFY_PLANS_URL_STYLE=myshopify');
            $this->line('to build the same URL through {shop}.myshopify.com/admin instead, then php artisan config:clear.');
        }

        return self::SUCCESS;
    }

    /**
     * Why a store has no Shopify figure yet, in the words a human can act on.
     */
    protected function unverifiedNote(Shop $shop): string
    {
        if (!$shop->hasUsableToken()) {
            return 'no live token — the store has not opened the app since installing';
        }

        if ($shop->plan !== config('shopify.default_plan')) {
            return 'Shopify has no active subscription for this plan — check the read-back with --live';
        }

        return 'Free, so nothing to compare';
    }

    /**
     * @return array<int, array<int, string|int>>
     */
    protected function configRows(): array
    {
        $managed = BillingService::shopifyManaged();
        $rows    = [];

        foreach ((array) config('shopify.plans') as $key => $plan) {
            $prices = (array) ($plan['prices'] ?? []);

            $rows[] = [
                $key,
                (string) ($plan['name'] ?? $key),
                $prices === []
                    ? 'free'
                    : collect($prices)->map(fn ($a, $c) => $this->money((float) $a, (string) $c))->join('  ·  '),
                (int) ($plan['trial_days'] ?? 0).' days',
                $managed ? 'display only' : 'charged to the merchant',
            ];
        }

        return $rows;
    }

    protected function money(float $amount, string $code): string
    {
        return $code.' '.number_format($amount, ($amount - floor($amount)) > 0.001 ? 2 : 0);
    }

    /**
     * @return Collection<int, Shop>
     */
    protected function shops(): Collection
    {
        $arg = trim((string) $this->argument('shop'));

        if ($arg === '') {
            return Shop::orderBy('id')->get();
        }

        $domain = mb_strtolower(preg_replace('#/.*$#', '', preg_replace('#^https?://#', '', $arg)));
        $shop   = Shop::where('domain', $domain)->first();

        if (!$shop) {
            $this->error('No shop row for '.$domain.' — check the domain, or install the app on that store first.');

            return new Collection();
        }

        return new Collection([$shop]);
    }
}
