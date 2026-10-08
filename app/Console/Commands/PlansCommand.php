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
 *   php artisan taskpe:plans house-of-indha.myshopify.com
 *
 * The exit code is non-zero while any list price disagrees with what Shopify bills, so a deploy
 * script can fail on it. A visible price is a number somebody now has to maintain — that is the
 * whole cost of showing one, and it is cheaper to check it here than to be told about it in review.
 */
class PlansCommand extends Command
{
    protected $signature = 'taskpe:plans
                            {shop? : myshopify.com domain (default: every shop)}
                            {--live : read each store\'s subscription from Shopify again before comparing}';

    protected $description = 'Show the plan price table and compare it with what Shopify bills each store';

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

        return $stale === 0 ? self::SUCCESS : self::FAILURE;
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
