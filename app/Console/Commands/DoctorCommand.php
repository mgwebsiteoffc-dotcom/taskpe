<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\BillingService;
use App\Services\ShopifyClient;
use App\Services\WebhookRegistrar;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Answers "is it the token, the key, or the deploy?" in one command.
 *
 * `Invalid API key or access token (unrecognized login or wrong password)` in
 * laravel.log is the same sentence for four different mistakes:
 *
 *   1. the app was uninstalled/reinstalled on the store (the old grant is revoked)
 *   2. the token was stored by ANOTHER server — `access_token` is encrypted with
 *      APP_KEY, so a moved host or a re-run `key:generate` breaks it
 *   3. SHOPIFY_API_KEY / SHOPIFY_API_SECRET belong to a different app than the one
 *      the merchant installed (including a stale `config:cache`)
 *   4. OAuth never finished, so the row has no token at all
 *
 * This prints the values actually in use, and finishes with a live Admin API probe,
 * so the verdict is Shopify's answer — not our guess.
 *
 *   php artisan taskpe:doctor                          # every shop
 *   php artisan taskpe:doctor house-of-indha.myshopify.com
 *   php artisan taskpe:doctor --skip-api               # local state only
 *   php artisan taskpe:doctor myshop.myshopify.com --register
 *
 * It also prints `order history`, which answers the other common report — "old orders
 * are missing from search" — by naming whichever of the three steps is still outstanding
 * (Shopify's approval, our scope request, or the store's reinstall).
 */
class DoctorCommand extends Command
{
    protected $signature = 'taskpe:doctor
                            {shop? : myshopify.com domain (default: every shop)}
                            {--skip-api : inspect local state only, no Admin API call}
                            {--register : re-register all webhooks when the probe passes}';

    protected $description = 'Diagnose a store: install state, access token, config, live Admin API probe, webhooks';

    public function handle(): int
    {
        $shops = $this->shops();

        if ($shops->isEmpty()) {
            $this->warn('No shop rows to inspect — a row is created when OAuth completes, so install the app on the store first.');

            return self::FAILURE;
        }

        $broken = 0;
        foreach ($shops as $shop) {
            $broken += $this->inspect($shop) ? 0 : 1;
        }

        if ($broken > 0) {
            $this->line('');
            $this->line('A rejected token fixes itself the moment OAuth runs again: open the app from Shopify');
            $this->line('admin (Apps → TaskPe), or send the merchant '.rtrim((string) config('shopify.app_url'), '/').'/auth/shopify?shop=<domain>.');
        }

        return $broken > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function shops(): Collection
    {
        $arg = trim((string) $this->argument('shop'));

        if ($arg === '') {
            return Shop::orderBy('id')->get();
        }

        $domain = mb_strtolower(preg_replace('#/.*$#', '', preg_replace('#^https?://#', '', $arg)));

        $shop = Shop::where('domain', $domain)->first();
        if (!$shop) {
            $this->error("No shops row for '{$domain}'. Known: ".(Shop::pluck('domain')->implode(', ') ?: '(none)'));

            return collect();
        }

        return collect([$shop]);
    }

    protected function inspect(Shop $shop): bool
    {
        $ok = true;

        $this->line('');
        $this->line('════ '.$shop->domain.' ════');

        // ---------- config that is actually loaded (config:cache included) ----------
        $key = (string) config('shopify.api_key');
        $this->row('client_id', $key === '' ? 'FAIL' : 'OK', $key === ''
            ? 'SHOPIFY_API_KEY is empty — Partner Dashboard → Apps → Client credentials, then php artisan config:clear'
            : substr($key, 0, 8).'… ('.strlen($key).' ch)');
        $ok = $key !== '' && $ok;

        $this->row('client_secret', config('shopify.api_secret') ? 'OK' : 'FAIL',
            config('shopify.api_secret') ? 'set' : 'SHOPIFY_API_SECRET empty — session tokens cannot even be verified');
        $ok = (bool) config('shopify.api_secret') && $ok;

        $this->row('app_url', rtrim((string) config('shopify.app_url'), '/') === '' ? 'FAIL' : 'OK',
            (string) config('shopify.app_url') ?: 'APP_URL empty — OAuth redirect_uri and webhook callbacks are built from it');
        $ok = rtrim((string) config('shopify.app_url'), '/') !== '' && $ok;

        $this->row('api_version', 'OK', (string) config('shopify.api_version'));

        // ---------- the row ----------
        $this->row('plan', 'OK', $shop->plan.' · limit '.($shop->planConfig()['task_limit'] ?? '?'));
        $this->row('installed_at', 'OK', (string) ($shop->installed_at ?? '—'));

        // "Why can't I find my old orders?" is the number one report on a store that has
        // had the app a short while, and the answer lives in three places at once: whether
        // Shopify approved the scope, whether we ask for it, and whether this store has
        // reinstalled since. So print all three — the row says which one is still missing
        // instead of leaving the reader to guess between a review and a deploy.
        $granted = collect(explode(',', strtolower(trim((string) $shop->scopes))))
            ->map(fn ($scope) => trim($scope))->filter()->values();

        if ($shop->canReadAllOrders()) {
            $this->row('order history', 'OK', 'every order, any date (read_all_orders is on this token)');
        } elseif ((bool) config('shopify.read_all_orders')) {
            $this->row('order history', 'WARN',
                'we ask for read_all_orders but this store granted: '.$granted->implode(', ').' — the scope only lands on a fresh install: open the app from Shopify admin once more (Apps → TaskPe → reinstall) after approval');
        } else {
            $this->row('order history', 'WARN',
                'orders created since '.((string) ($shop->orderSearchSince() ?: ($shop->installed_at ?? 'the install date'))).' only — Shopify will not let this token read older ones until read_all_orders is approved (Partner Dashboard → your app → API access → Protected customer data), then set SHOPIFY_READ_ALL_ORDERS=true + php artisan config:clear');
        }

        // Billing is the other question a merchant asks that has nothing to do with tokens:
        // who sets the price, and does the app know what Shopify is actually charging? One row,
        // both answers, and it says so out loud when nothing has ever been read back.
        $bill = (array) $shop->setting('billing', []);
        $says = !$bill
            ? 'nothing read from Shopify yet — open the Plan tab and press "Check again"'
            : (empty($bill['subscribed'])
                ? 'no active subscription as of '.($bill['read_at'] ?? '—').' → plan stays '.config('shopify.default_plan')
                : trim(($bill['name'] ?? 'unnamed plan').' · '
                    .(isset($bill['amount']) && $bill['amount'] !== null
                        ? $bill['amount'].' '.($bill['currency'] ?? '?')
                        : 'amount not readable from this plan type (usage/one-time pricing)')
                    .' · renews '.($bill['renews_at'] ?? '—')
                    .(($bill['test'] ?? false) ? ' · TEST charge' : '')));

        $this->row('billing', BillingService::shopifyManaged() ? 'OK' : 'WARN',
            (BillingService::shopifyManaged()
                ? 'priced and billed by Shopify (Partner Dashboard); appSubscriptionCreate refused here on purpose'
                : 'SHOPIFY_BILLING_MODE=api — this app creates the charge, so config prices must match the Dashboard plan')
            .' · '.$says);

        if ($rejection = $shop->tokenRejection()) {
            $this->row('token rejected', 'WARN', $rejection);
        }

        if ($shop->uninstalled_at) {
            $this->row('uninstalled_at', $shop->tokenRejected() ? 'FAIL' : 'WARN',
                $shop->tokenRejected()
                    ? 'flagged after Shopify refused our token — reconnect to mint a new one'
                    : 'app/uninstalled webhook ran; tokens are revoked with the install');
            $ok = false;
        }

        // ---------- the token (never printed) ----------
        $fingerprint = $shop->tokenFingerprint();
        if (str_starts_with($fingerprint, 'undecryptable')) {
            $this->row('access_token', 'FAIL', $fingerprint.' — APP_KEY on this server is not the one that encrypted it; reinstall the app (or restore the old APP_KEY)');
            $ok = false;
        } elseif ($fingerprint === 'none') {
            $this->row('access_token', 'FAIL', 'empty — OAuth never completed on this host for this domain');
            $ok = false;
        } else {
            $this->row('access_token', 'OK', 'sha256:'.$fingerprint.' (value not printed)');
        }

        // The token's *shape* now matters as much as its value. A public app holding the old
        // non-expiring kind has a token that decrypts, belongs to the right store, carries the
        // right scopes — and is refused by the GraphQL Admin API anyway. Reporting that row as
        // OK on the strength of the fingerprint alone would be the most expensive green light in
        // this command.
        if (!$shop->hasUsableToken()) {
            // already covered by the two FAIL rows above
        } elseif (!$shop->usesExpiringToken()) {
            $this->row('token kind', 'FAIL', 'non-expiring — the Admin API refuses that kind for public apps. php artisan taskpe:tokens --rotate converts it in place; no reinstall for the merchant');
            $ok = false;
        } else {
            $left  = $shop->tokenSecondsLeft();
            $renew = $shop->refreshSecondsLeft();

            if (!$shop->refreshPossible()) {
                $this->row('token kind', 'FAIL', 'expiring but not renewable — no usable refresh token on the row, so the merchant has to open the app once');
                $ok = false;
            } else {
                $this->row('token kind', 'OK', 'expiring · access token '.($left !== null && $left <= 0 ? 'overdue' : 'valid ~'.($left === null ? '?' : (int) round($left / 60)).' min more')
                    .' · renewable for ~'.($renew === null ? '?' : (int) round($renew / 86400)).' days');
            }
        }

        if ($this->option('skip-api')) {
            $this->row('probe', 'WARN', 'skipped (--skip-api)');

            return $ok;
        }

        // ---------- the only verdict that matters: ask Shopify ----------
        try {
            $data = (new ShopifyClient($shop))->graphql('{ shop { name myshopify_domain currencyCode } }');
            $returned = (string) ($data['shop']['myshopify_domain'] ?? '');

            if ($returned !== '' && $returned !== $shop->domain) {
                $this->row('probe', 'FAIL', "token answered for '{$returned}', not '{$shop->domain}' — this row holds another store's token");
                $ok = false;
            } else {
                $this->row('probe', 'OK', ($data['shop']['name'] ?? 'shop').' · '.$returned.' · '.($data['shop']['currencyCode'] ?? '?'));
            }
        } catch (\Throwable $e) {
            $this->row('probe', 'FAIL', $e->getMessage());
            $ok = false;
        }

        if (!$ok) {
            return false;
        }

        $this->webhooks($shop);

        if ($this->option('register')) {
            (new WebhookRegistrar())->registerAll($shop);
            $this->row('webhooks', 'OK', 're-registered (see the log for any userErrors)');
        }

        return $ok;
    }

    /** Which of the configured topics Shopify says exist. Absent ones are silent breakage. */
    protected function webhooks(Shop $shop): void
    {
        $wanted = (array) config('shopify.webhook_topics', []);

        try {
            $data = (new ShopifyClient($shop))->graphql(
                '{ webhookSubscriptions(first: 20) { edges { node { topic endpoint { url } } } } }'
            );
        } catch (\Throwable $e) {
            $this->row('webhooks', 'WARN', 'could not list: '.$e->getMessage());

            return;
        }

        $edges = $data['webhookSubscriptions']['edges'] ?? [];
        $have = [];
        $wrongHost = [];
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? [];
            $topic = (string) ($node['topic'] ?? '');
            $url = (string) ($node['endpoint']['url'] ?? '');
            $have[] = $topic;
            $expected = rtrim((string) config('shopify.app_url'), '/').'/webhooks/shopify';
            if ($url !== '' && $url !== $expected) {
                $wrongHost[] = $topic.' → '.$url;
            }
        }

        $missing = array_values(array_diff($wanted, $have));
        if ($missing !== []) {
            $this->row('webhooks', 'WARN', 'not registered: '.implode(', ', $missing).' — php artisan taskpe:register-webhooks '.$shop->domain);
        } else {
            $this->row('webhooks', 'OK', count($have).' subscribed, all expected topics present');
        }

        if ($wrongHost !== []) {
            $this->row('webhook endpoint', 'WARN', 'points elsewhere: '.implode(', ', $wrongHost).' (APP_URL changed after install — re-register to move them)');
        }
    }

    protected function row(string $label, string $state, string $detail): void
    {
        $paint = match ($state) {
            'OK'   => fn (string $t) => $this->output->writeln('  <fg=green>'.$t.'</>'),
            'WARN' => fn (string $t) => $this->output->writeln('  <fg=yellow>'.$t.'</>'),
            default => fn (string $t) => $this->output->writeln('  <fg=red>'.$t.'</>'),
        };

        $paint(str_pad($state, 5).str_pad($label, 18).' '.$detail);
    }
}
