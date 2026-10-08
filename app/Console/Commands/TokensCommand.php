<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyClient;
use App\Services\TokenVault;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The Admin API token, inspected and kept alive.
 *
 * Public apps now hold an EXPIRING offline token: about an hour of access plus a
 * 90-day refresh token that is replaced on every renewal. Requests renew it by
 * themselves (App\Services\TokenVault), so this command is for the three moments where
 * a human wants the answer out loud:
 *
 *   - "is this store on the old token?"      → kind: legacy, and `--rotate` converts it
 *   - "why did the board stop after a while?" → the refresh token ran out while nobody
 *                                              opened the app; reconnect once, then the
 *                                              daily pass keeps it from happening again
 *   - "did the deploy break auth?"           → `--probe`, which asks Shopify rather
 *                                              than guessing from our own state
 *
 *   php artisan taskpe:tokens                        every store, one line each
 *   php artisan taskpe:tokens house-of-indha.myshopify.com
 *   php artisan taskpe:tokens --rotate               convert legacy + renew anything close to death
 *   php artisan taskpe:tokens --rotate --force       the same, for every store right now
 *   php artisan taskpe:tokens --probe                live Admin API answer per store
 *
 * `--rotate` is what the scheduler runs daily. It only spends a request on stores whose
 * refresh token is inside 14 days of expiry, so the call volume stays trivial while the
 * safety margin stays a fortnight wide.
 */
class TokensCommand extends Command
{
    protected $signature = 'taskpe:tokens
                            {shop? : myshopify.com domain (default: every shop)}
                            {--rotate : convert legacy tokens and renew anything close to expiry}
                            {--probe : call the Admin API once per store and report Shopify\'s answer}
                            {--force : with --rotate, renew every store instead of only those due}';

    protected $description = 'Show and maintain each store\'s Shopify Admin API token (expiring offline tokens)';

    /** Renew a refresh token this far before it dies (days). */
    protected const RENEW_WINDOW_DAYS = 14;

    public function handle(): int
    {
        if (!TokenVault::hasTokenColumns()) {
            $this->error('The shops table has no token columns yet — run: php artisan migrate --force');
            $this->line('Until then this app keeps saving a single non-expiring token, which the');
            $this->line('GraphQL Admin API refuses for public apps. The migration is the fix, not a config value.');

            return self::FAILURE;
        }

        $shops = $this->shops();

        if ($shops->isEmpty()) {
            $this->warn('No shop rows — a row appears when OAuth completes, so install the app on the store first.');

            return self::FAILURE;
        }

        $rows = [];
        $needsAttention = 0;

        foreach ($shops as $shop) {
            $action = $this->option('rotate') ? $this->maintain($shop) : null;
            $probe  = $this->option('probe') ? $this->probe($shop) : '';

            [$kind, $access, $refresh] = $this->state($shop);

            if ($kind !== 'expiring') {
                $needsAttention++;
            }

            $rows[] = [$shop->domain, $kind, $access, $refresh, trim($action.' '.$probe)];
        }

        $this->table(['store', 'token kind', 'access left', 'renewable for', 'action'], $rows);

        $this->line('');

        if ($needsAttention === 0) {
            $this->line('Every store is on an expiring offline token with a refresh path. Nothing to do; '
                .'the daily pass (routes/console.php) keeps it that way.');
        } else {
            $this->line('<comment>'.$needsAttention.' store(s) are not on a usable expiring token.</comment>');
            $this->line($this->option('rotate')
                ? 'The automatic path is exhausted for those — the merchant opening the app once from '
                  .'Shopify admin runs OAuth again, which always mints a fresh pair.'
                : 'php artisan taskpe:tokens --rotate converts them in place, with no reinstall for anyone.');
        }

        return $needsAttention === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    protected function state(Shop $shop): array
    {
        if (!$shop->hasUsableToken()) {
            return ['none — never installed or reinstalled', '—', '—'];
        }

        if (!$shop->usesExpiringToken()) {
            return ['legacy (non-expiring)', 'unknown', 'no refresh token stored'];
        }

        $left = $shop->tokenSecondsLeft();
        $renew = $shop->refreshSecondsLeft();

        $kind = 'expiring';

        if ($shop->tokenRejected()) {
            $kind = 'expiring, but Shopify rejected it';
        }

        return [
            $kind,
            $left === null ? '—' : ($left > 0 ? $this->span($left) : 'expired '.$this->span(-$left).' ago'),
            $renew === null ? 'not renewable — reconnect needed' : ($renew > 0 ? $this->span($renew) : 'ran out '.$this->span(-$renew).' ago'),
        ];
    }

    /** @return string what was done, for the table */
    protected function maintain(Shop $shop): string
    {
        $vault = new TokenVault($shop);

        try {
            if (!$shop->usesExpiringToken() && $shop->hasUsableToken()) {
                return $vault->convertLegacy(true)
                    ? 'migrated to an expiring token'
                    : 'could not migrate (see laravel.log)';
            }

            $due = $shop->tokenNeedsRotation((int) config('shopify.token_refresh_skew', 120))
                || $this->renewDueSoon($shop);

            if (!$this->option('force') && !$due) {
                return 'left alone (not due)';
            }

            if (!$shop->refreshPossible()) {
                return 'cannot renew — reconnect this store once from Shopify admin';
            }

            $before = $shop->tokenFingerprint();

            if ($vault->rotate(true)) {
                $shop->refresh();

                return $shop->tokenFingerprint() !== $before
                    ? 'rotated (new pair stored)'
                    : 'rotated';
            }

            return 'rotation deferred (Shopify busy)';
        } catch (\RuntimeException $e) {
            return 'FAILED: '.$e->getMessage();
        }
    }

    /** Within RENEW_WINDOW_DAYS of the refresh token running out? */
    protected function renewDueSoon(Shop $shop): bool
    {
        $left = $shop->refreshSecondsLeft();

        return $left !== null && $left < self::RENEW_WINDOW_DAYS * 86400;
    }

    protected function probe(Shop $shop): string
    {
        try {
            $data = (new ShopifyClient($shop))->graphql('{ shop { name } }');

            return 'probe ok: '.($data['shop']['name'] ?? 'answered');
        } catch (\Throwable $e) {
            return 'probe FAILED: '.mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120);
        }
    }

    /** "3d 4h", "41m", "52s" — long enough to see a trend, short enough for a table. */
    protected function span(int $seconds): string
    {
        $seconds = abs($seconds);

        if ($seconds >= 86400) {
            return floor($seconds / 86400).'d '.floor(($seconds % 86400) / 3600).'h';
        }

        if ($seconds >= 3600) {
            return floor($seconds / 3600).'h '.floor(($seconds % 3600) / 60).'m';
        }

        if ($seconds >= 60) {
            return floor($seconds / 60).'m';
        }

        return $seconds.'s';
    }

    protected function shops(): Collection
    {
        $arg = trim((string) $this->argument('shop'));

        if ($arg === '') {
            return Shop::orderBy('id')->get();
        }

        $domain = mb_strtolower(preg_replace('#/.*$#', '', preg_replace('#^https?://#', '', $arg)));
        $shop   = Shop::where('domain', $domain)->first();

        if (!$shop) {
            $this->error('No shop row for '.$domain.' — check the domain, or install the app on that store.');

            return new Collection();
        }

        return new Collection([$shop]);
    }
}
