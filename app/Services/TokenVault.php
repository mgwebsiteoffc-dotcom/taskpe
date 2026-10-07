<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Owns the store's Admin API credentials.
 *
 * Shopify changed the rules for public apps: a NON-expiring offline token is now
 * refused by the GraphQL Admin API ("Non-expiring access tokens are no longer
 * accepted for the Admin API"). The replacement lives one hour and is renewed with
 * the refresh token issued beside it, and every refresh hands back a NEW refresh
 * token with a fresh 90 days. A token is therefore no longer a string saved once at
 * install — it is a pair with two expiry dates that has to keep moving, and an app
 * that does not move it stops working one hour (or one deploy) after it started.
 *
 * This class is the only place that reads, renews, converts or writes them:
 *
 *   token()          the header value for the next request, rotated first if it is stale
 *   rotate()         the refresh_token grant, for background work with no merchant present
 *   convertLegacy()  Shopify's documented migration exchange, so stores that installed
 *                    before this change do not have to uninstall and reinstall
 *   store()          the single writer of the pair + both expiry dates
 *
 * Four rules from Shopify's own text shaped the code:
 *
 *  - "Read the `expires_in` value from the token response rather than hard-coding a
 *    duration" — lifetimes come from the response; 3600 is never assumed.
 *  - "Refresh one store at a time. Two workers refreshing the same store concurrently
 *    can leave one of them holding a token the other has already replaced." — hence the
 *    per-store lock, and the row re-read inside it.
 *  - A terminal refresh failure is always `401 {"error":"invalid_request"}` ("This
 *    request requires an active refresh_token"), whatever the cause, and the advice is
 *    explicit: treat it as final, stop retrying, re-authenticate on next open. Transient
 *    ones (timeout, 5xx, 429) are safe to retry with the token still held, so those are
 *    logged and the still-valid access is kept rather than thrown away.
 *  - "Don't do the two at the same time for the same store" (acquiring during OAuth and
 *    refreshing in a job) — so right after a grant the vault stands down.
 *
 * Nothing here is ever sent to the browser: the pair stays server-side, encrypted with
 * APP_KEY, and only the 8-character fingerprint reaches a log line.
 */
class TokenVault
{
    /** Memo for the column probe, so a pre-migration deploy degrades without chattering. */
    protected static ?bool $columns = null;

    public function __construct(protected Shop $shop) {}

    /*
    |--------------------------------------------------------------------------
    | The one read path
    |--------------------------------------------------------------------------
    */

    /**
     * The token to put on the next Admin API call, renewed if it is close to expiry.
     *
     * '' means "there is nothing usable here" — callers then behave like a store that
     * is not installed, which is what the SPA already knows how to show (a reconnect
     * button) instead of a 500 with an empty Authorization header.
     */
    public function token(): string
    {
        $shop = $this->shop;

        $access = $this->read('access_token');

        if ($access === '') {
            return '';
        }

        // The legacy kind: stored before this change, no expiry recorded, no refresh
        // token. Convert it in place the first time it is needed — that is one extra
        // request for a store that is otherwise heading for a hard stop on
        // 1 January 2027, and it is the difference between "your data is safe, reopen
        // the app" and "uninstall, reinstall, re-link your tasks".
        if ($this->isLegacy()) {
            $this->convertLegacy();
            $access = $this->read('access_token') ?: $access;
        }

        if ($shop->tokenNeedsRotation($this->skew())) {
            if (!$shop->refreshPossible()) {
                // No way to renew without the merchant. Flag the store (every gate
                // listens to that flag and offers the reconnect) and say what to do.
                $shop->markTokenRejected('the access token expired and no usable refresh token is stored');

                throw new \RuntimeException($this->reauthMessage(), 401);
            }

            $this->rotate();
            $access = $this->read('access_token') ?: $access;
        }

        return $access;
    }

    /**
     * Force the pair for a token Shopify has just refused: the caller (ShopifyClient)
     * replays its request once and reads the new value through token().
     */
    public function renewNow(): bool
    {
        if ($this->isLegacy()) {
            return $this->convertLegacy(true);
        }

        return $this->rotate(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Rotation and migration
    |--------------------------------------------------------------------------
    */

    /**
     * Exchange the stored refresh token for a new pair.
     *
     * @return bool true when a fresh pair was saved
     */
    public function rotate(bool $force = false): bool
    {
        $shop = $this->shop;

        if (!$force && $shop->recentlyGranted()) {
            return false;      // OAuth is mid-flight for this store; do not race it
        }

        if (!$shop->refreshPossible()) {
            return false;
        }

        return (bool) $this->withLock(function () use ($force) {
            $shop = $this->shop;
            $shop->refresh();                       // whoever held the lock may have done this

            if (!$force && !$shop->tokenNeedsRotation($this->skew())) {
                return true;                       // already fresh: nothing to do, not a failure
            }

            $refresh = $shop->refreshToken();

            if ($refresh === null) {
                return false;
            }

            $reply = $this->exchange([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
            ]);

            if ($reply['ok']) {
                $this->store($reply['json']);
                $shop->clearTokenRejection();

                Log::info('TaskPe rotated this store\'s Admin API token', [
                    'shop'          => $shop->domain,
                    'token'         => $shop->tokenFingerprint(),
                    'expires_in'    => $reply['json']['expires_in'] ?? null,
                    'refresh_in'    => $reply['json']['refresh_token_expires_in'] ?? null,
                ]);

                return true;
            }

            if ($reply['terminal']) {
                // Replaced by a newer grant, expired, or the app was uninstalled — the
                // answer is the same for all of them, and it is not a retry.
                $shop->markTokenRejected('Shopify no longer accepts the stored refresh token ('.$reply['error'].')');

                Log::warning('TaskPe could not rotate this store\'s token — reconnect required', [
                    'shop'  => $shop->domain,
                    'error' => $reply['error'],
                    'token' => $shop->tokenFingerprint(),
                ]);

                throw new \RuntimeException($this->reauthMessage(), 401);
            }

            // Busy, slow or a 5xx: keep the hour we still have and let the next call try.
            Log::warning('Token rotation deferred (transient Shopify error)', [
                'shop'  => $shop->domain,
                'error' => $reply['error'],
                'retry' => gmdate('c', time() + $this->skew()),
            ]);

            return false;
        });
    }

    /**
     * Convert a stored NON-expiring offline token into the expiring pair, with no
     * merchant present and no ID token — Shopify's direct migration flow for exactly
     * this situation (public apps that requested non-expiring tokens before the change).
     *
     * Caution from the same page: the migration invalidates the old token for GraphQL
     * requests, so the pair is written in the same step and never after a retry loop;
     * and if the response is lost, repeating the request within seven days returns the
     * same pair, which is why a failed *write* keeps the old token on the row.
     *
     * @return bool true when this store now holds an expiring pair
     */
    public function convertLegacy(bool $force = false): bool
    {
        $shop = $this->shop;

        if (!$this->isLegacy()) {
            return $shop->usesExpiringToken();
        }

        // One attempt per hour unless someone asks for it now: a store whose token is
        // not eligible (already revoked, or a custom-app token) must not be re-posted
        // on every board load.
        $tried = $shop->setting('auth.legacy_try_at');

        if (!$force && is_string($tried) && (time() - strtotime($tried)) < (int) config('shopify.token_migrate_retry', 3600)) {
            return false;
        }

        $shop->setSetting('auth.legacy_try_at', gmdate('c'));
        $shop->save();

        return (bool) $this->withLock(function () use ($shop) {
            $access = $this->read('access_token');

            if ($access === '') {
                return false;
            }

            $reply = $this->exchange([
                'grant_type'           => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token'        => $access,
                'subject_token_type'   => 'urn:shopify:params:oauth:token-type:offline-access-token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                'expiring'             => 1,
            ]);

            if ($reply['ok']) {
                $this->store($reply['json']);
                $shop->clearTokenRejection();
                $shop->setSetting('auth.migrated_at', now()->toIso8601String());
                $shop->save();

                Log::info('TaskPe migrated this store to an expiring offline token', [
                    'shop'       => $shop->domain,
                    'token'      => $shop->tokenFingerprint(),
                    'expires_in' => $reply['json']['expires_in'] ?? null,
                ]);

                return true;
            }

            // invalid_subject_token = what we hold is not an eligible non-expiring
            // offline token. The old token may still work (custom apps and merchant-built
            // apps are exempt from the change), so this is a note, not an outage: no
            // markTokenRejected here, and the next attempt is an hour away.
            Log::warning('TaskPe could not migrate this store to an expiring token', [
                'shop'    => $shop->domain,
                'status'  => $reply['status'],
                'error'   => $reply['error'],
                'next'    => 'the merchant reopening the app from Shopify admin always works, because OAuth mints a new pair',
            ]);

            return false;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | The single writer
    |--------------------------------------------------------------------------
    */

    /**
     * Save one token response: the access token, the refresh token beside it, and both
     * lifetimes as absolute timestamps (relative seconds age badly across queues).
     *
     * A response without a refresh token does NOT clear the stored one: Shopify issues
     * both together, so a partial write would strand the pair and cost the store its
     * renewal path. Scopes are taken from the response readback, because that is what
     * the token can actually do — which `canReadAllOrders()` depends on.
     */
    public function store(array $pair): void
    {
        $shop = $this->shop;

        $attributes = [
            'access_token' => (string) ($pair['access_token'] ?? ''),
        ];

        if (!empty($pair['scope'])) {
            $attributes['scopes'] = (string) $pair['scope'];
        }

        if (self::hasTokenColumns()) {
            $attributes['token_rotated_at'] = now();

            if (!empty($pair['expires_in'])) {
                $attributes['token_expires_at'] = now()->addSeconds(max(1, (int) $pair['expires_in']));
            }

            if (!empty($pair['refresh_token'])) {
                $attributes['refresh_token'] = (string) $pair['refresh_token'];
                $attributes['refresh_expires_at'] = now()->addSeconds(max(1, (int) ($pair['refresh_token_expires_in'] ?? 7776000)));
            }
        }

        $shop->forceFill($attributes)->save();

        // The expiry is the whole point of the change, so say out loud when the row
        // cannot hold it: that is a deploy that ran the code before the migration.
        if (!self::hasTokenColumns()) {
            Log::warning('Shopify token stored without expiry columns — run php artisan migrate --force', [
                'shop' => $shop->domain,
            ]);
        }
    }

    /** Whether the expiring-token columns exist (probed once per request). */
    public static function hasTokenColumns(): bool
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        try {
            self::$columns = Schema::hasColumn('shops', 'refresh_token')
                && Schema::hasColumn('shops', 'token_expires_at');
        } catch (\Throwable $e) {
            self::$columns = false;
        }

        return self::$columns;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * POST to the store's own token endpoint. Both grants — refresh and migration —
     * are the same call with different parameters, and both answer in the same shape.
     *
     * @return array{ok:bool,terminal:bool,status:int,error:string,json:array}
     */
    protected function exchange(array $form): array
    {
        $url = "https://{$this->shop->domain}/admin/oauth/access_token";

        try {
            $response = Http::timeout(15)->asForm()->post($url, $form + [
                'client_id'     => (string) config('shopify.api_key'),
                'client_secret' => (string) config('shopify.api_secret'),
            ]);
        } catch (\Throwable $e) {
            // Network and timeout failures are, by Shopify's own list, transient:
            // retry later with the refresh token still on the row.
            return ['ok' => false, 'terminal' => false, 'status' => 0, 'error' => 'network: '.$e->getMessage(), 'json' => []];
        }

        $status = $response->status();
        $json   = $response->json() ?: [];

        if ($status === 429 || $status >= 500) {
            return ['ok' => false, 'terminal' => false, 'status' => $status, 'error' => 'Shopify HTTP '.$status, 'json' => $json];
        }

        if ($status >= 200 && $status < 300 && !empty($json['access_token'])) {
            return ['ok' => true, 'terminal' => false, 'status' => $status, 'error' => '', 'json' => $json];
        }

        $error = (string) ($json['error_description'] ?? $json['error'] ?? 'HTTP '.$status);

        return [
            'ok'       => false,
            // 401 and 400 are Shopify's "this grant is not usable" answers — retrying
            // them only burns the window in which a merchant could still be working.
            'terminal' => $status === 401 || $status === 400 || $status === 403,
            'status'   => $status,
            'error'    => $error ?: ('HTTP '.$status),
            'json'     => $json,
        ];
    }

    /**
     * One rotation per store at a time. If the lock cannot be taken (no cache store,
     * or its table is missing) the work still goes ahead: a rare double rotation is
     * survivable because the refresh token presented stays usable until the new one is
     * used, whereas refusing to rotate is what stops the board.
     */
    protected function withLock(callable $fn): mixed
    {
        try {
            $lock = Cache::lock('taskpe:token:'.$this->shop->id, (int) config('shopify.token_lock_seconds', 25));
        } catch (\Throwable $e) {
            return $fn();
        }

        try {
            if (!$lock->acquire()) {
                try {
                    if ($lock->block(8)) {
                        $lock->release();
                    }
                } catch (\Throwable $e) {
                    // LockTimeoutException: the other worker is slow. Carry on with what
                    // the row already holds instead of queueing behind it.
                }

                $this->shop->refresh();

                return false;
            }
        } catch (\Throwable $e) {
            return $fn();
        }

        try {
            return $fn();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                // Expired on its own; nothing to release.
            }
        }
    }

    /** Refresh a token this many seconds before it dies (an hour is short; a queue lag is not). */
    protected function skew(): int
    {
        return max(60, (int) config('shopify.token_refresh_skew', 120));
    }

    /** The stored token is the old, non-expiring kind: no expiry recorded, no refresh token. */
    protected function isLegacy(): bool
    {
        $shop = $this->shop;

        return $this->read('access_token') !== ''
            && !$shop->usesExpiringToken()
            && $shop->refreshToken() === null
            && !$shop->recentlyGranted();
    }

    /**
     * Reading an `encrypted` column THROWS when APP_KEY changed (a moved host, a
     * re-run key:generate). Secrets are read through here so that case degrades into
     * "no token, go through OAuth" — the same shape as Shop::hasUsableToken() —
     * instead of a 500 on every board load.
     */
    protected function read(string $column): string
    {
        try {
            $value = $this->shop->{$column};
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($value) ? $value : '';
    }

    /**
     * What the merchant reads when renewal is impossible. No jargon, no "check scopes":
     * one action, in the place it has to be done, and the promise that nothing is lost.
     */
    protected function reauthMessage(): string
    {
        return 'TaskPe\'s Shopify login expired and could not be renewed automatically. '
            .'Open TaskPe from Apps in your Shopify admin to reconnect it — one click, and your '
            .'boards, tasks and links stay as they are.';
    }
}
