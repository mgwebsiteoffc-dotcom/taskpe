<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shop extends Model
{
    protected $fillable = [
        'domain', 'handle', 'name', 'access_token', 'scopes',
        'plan', 'charge_id', 'timezone', 'currency',
        'whatify_api_key', 'whatify_account_id', 'settings',
        'installed_at', 'uninstalled_at', 'redacted_at',
        // Public apps now hold an EXPIRING offline token plus its refresh token; both
        // lifetimes are stored as absolute dates. Written only by TokenVault::store().
        'refresh_token', 'token_expires_at', 'refresh_expires_at', 'token_rotated_at',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'whatify_api_key'  => 'encrypted',
        'settings'         => 'array',
        'installed_at'     => 'datetime',
        'uninstalled_at'   => 'datetime',
        'redacted_at'      => 'datetime',
        'token_expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'token_rotated_at' => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token', 'whatify_api_key'];

    // ---------------- relationships ----------------

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function whatsappLogs(): HasMany
    {
        return $this->hasMany(WhatsappLog::class);
    }

    // ---------------- plan / settings helpers ----------------

    public function planConfig(): array
    {
        return config("shopify.plans.{$this->plan}", config('shopify.plans.'.config('shopify.default_plan')));
    }

    public function isPaid(): bool
    {
        return \App\Services\BillingService::isPaidPlan($this->plan);
    }

    /**
     * The WhatsApp feature is OFF by default — the merchant explicitly
     * enables it in Settings (Admin panel toggle). All three conditions:
     * plan includes it + Whatify key present + master switch ON.
     */
    public function whatsappEnabled(): bool
    {
        return (bool) ($this->planConfig()['whatsapp'] ?? false)
            && !empty($this->whatify_api_key)
            && $this->whatsappMasterOn();
    }

    public function whatsappMasterOn(): bool
    {
        return (bool) $this->setting('notify.whatsapp_on', false);
    }

    /** Read a dot-notated value from the JSON settings column. */
    public function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings ?? [];
        foreach (explode('.', $key) as $seg) {
            if (!is_array($value) || !array_key_exists($seg, $value)) {
                return $default;
            }
            $value = $value[$seg];
        }

        return $value;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
    }

    /**
     * `read_all_orders` is what unlocks order history from before the install.
     * Public apps only get it after Shopify approves the protected customer data
     * review, so NOT having it is normal, not a broken install. Once approved,
     * SHOPIFY_READ_ALL_ORDERS (config/shopify.php) is what asks for it; this method
     * reports only what the store actually granted, because that is the only answer
     * a query can be planned around.
     */
    public function canReadAllOrders(): bool
    {
        // Only what THIS store's token was actually granted. The scopes in the config are
        // what we ask for, and `read_all_orders` sits in that list the moment the switch is
        // on but before the store has reinstalled — reading the config here would promise a
        // full history the token cannot deliver, and the search would come back as an
        // ACCESS_DENIED instead of the bounded answer that is true today.
        $granted = trim((string) $this->scopes);

        if ($granted === '') {
            return false;
        }

        // strtolower() on the STRING, then split. The other order of operations hands
        // strtolower() an array, which is a TypeError rather than a false — and since
        // orderSearchSince() calls this on every order search, one misplaced parenthesis
        // made the whole search feature fail before Shopify was even asked.
        return collect(explode(',', strtolower($granted)))
            ->contains(fn ($scope) => trim($scope) === 'read_all_orders');
    }

    /**
     * Without that approval an offline token may only read orders created after
     * the app was installed. Searching the whole history costs a GraphQL
     * ACCESS_DENIED instead of an empty list, so callers bound the query by this
     * date and say so in the UI. Null = no bound (approved, or no install date).
     */
    public function orderSearchSince(): ?string
    {
        if ($this->canReadAllOrders()) {
            return null;
        }

        $since = $this->installed_at ?? $this->created_at;

        return $since ? $since->toDateString() : null;
    }

    public function isInstalled(): bool
    {
        return is_null($this->uninstalled_at) && $this->hasUsableToken();
    }

    /**
     * A token that cannot be decrypted is as unusable as a missing one — and
     * reading an `encrypted` column THROWS, so every gate would 500 instead of
     * sending the merchant through OAuth. This happens for real when a store is
     * moved between servers (new APP_KEY) or after `php artisan key:generate`.
     */
    public function hasUsableToken(): bool
    {
        try {
            return !empty($this->access_token);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ---------------- token lifetime (expiring offline tokens) ----------------

    /**
     * True when the row carries the expiry Shopify issues with an expiring offline
     * token. False is not a broken install: it is a store that installed before the
     * change, holding the non-expiring kind the Admin API now refuses — which is what
     * TokenVault::convertLegacy() exists to fix without asking anyone to reinstall.
     */
    public function usesExpiringToken(): bool
    {
        return $this->token_expires_at !== null;
    }

    /**
     * The access token is due for renewal once it is inside `$skew` seconds of death.
     *
     * A missing/unknown expiry returns false deliberately: "we do not know when this
     * one dies" is the legacy case, and rotating a legacy token is impossible — it has
     * no refresh token. The migration is the answer there, not a rotation.
     */
    public function tokenNeedsRotation(int $skew = 0): bool
    {
        $until = $this->token_expires_at;

        if (!$until instanceof \DateTimeInterface) {
            return false;
        }

        return $until->getTimestamp() - time() <= max(0, $skew);
    }

    /**
     * Can this store renew itself without the merchant? Only with a refresh token that
     * has not run out. Decrypting it can THROW (APP_KEY changed), and an unreadable
     * secret means "no", which routes the merchant to OAuth rather than into a 500.
     */
    public function refreshPossible(): bool
    {
        $until = $this->refresh_expires_at;

        if ($until instanceof \DateTimeInterface && $until->getTimestamp() <= time()) {
            return false;
        }

        return $this->refreshToken() !== null;
    }

    public function refreshToken(): ?string
    {
        try {
            $value = $this->refresh_token;
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Seconds of access left, or null when this row does not track it (legacy). */
    public function tokenSecondsLeft(): ?int
    {
        $until = $this->token_expires_at;

        return $until instanceof \DateTimeInterface ? $until->getTimestamp() - time() : null;
    }

    /**
     * Seconds the refresh token has left (the 90-day window). Null means "not tracked":
     * either there is no refresh token, or the row predates the migration and cannot say.
     * Reporting a guess here would send someone off to fix the wrong thing, so a status
     * command prints "unknown" instead.
     */
    public function refreshSecondsLeft(): ?int
    {
        $until = $this->refresh_expires_at;

        return $until instanceof \DateTimeInterface ? $until->getTimestamp() - time() : null;
    }

    /**
     * Shopify: "Don't do the two at the same time for the same store — acquiring a
     * token and refreshing one each retire the result of the other." A grant younger
     * than this window means OAuth is still landing, so background rotation stands down.
     */
    public function recentlyGranted(int $seconds = 120): bool
    {
        $at = $this->token_rotated_at ?? $this->installed_at;

        return $at instanceof \DateTimeInterface && (time() - $at->getTimestamp()) < $seconds;
    }

    /**
     * Shopify has rejected this install's access token: the app was uninstalled
     * or reinstalled (which revokes the old grant), the token was minted by a
     * different app, or it was encrypted with another server's APP_KEY.
     *
     * Flipping uninstalled_at is deliberate — it is the one signal every gate
     * already listens to. The SPA sees `not_installed` and restarts OAuth at top
     * level, which mints a fresh token; the reason is parked in settings so the
     * screen and the log can say WHY instead of "not connected yet".
     */
    public function markTokenRejected(string $why): void
    {
        if (!is_null($this->uninstalled_at) && (string) $this->setting('auth.rejected_reason', '') === $why) {
            return;                                     // already flagged, don't churn rows
        }

        $this->uninstalled_at = now();
        $this->setSetting('auth.rejected_at', now()->toIso8601String());
        $this->setSetting('auth.rejected_reason', mb_substr($why, 0, 200));
        $this->save();
    }

    public function tokenRejected(): bool
    {
        return !empty($this->setting('auth.rejected_at'));
    }

    public function tokenRejection(): ?string
    {
        $at = $this->setting('auth.rejected_at');

        return $at ? $at.' — '.$this->setting('auth.rejected_reason', '') : null;
    }

    /** Called on a successful OAuth: the new grant is valid, forget the complaint. */
    public function clearTokenRejection(): void
    {
        if (!$this->tokenRejected()) {
            return;
        }

        $settings = $this->settings ?? [];
        unset($settings['auth']['rejected_at'], $settings['auth']['rejected_reason']);

        if (empty($settings['auth'])) {
            unset($settings['auth']);
        }

        $this->settings = $settings;
        $this->save();
    }

    /**
     * 8 hex of sha256 + length — enough to tell two rows (or two servers) apart
     * in a log line without ever writing the token itself.
     */
    public function tokenFingerprint(): string
    {
        try {
            $token = (string) $this->access_token;
        } catch (\Throwable $e) {
            return 'undecryptable(APP_KEY changed)';
        }

        return $token === '' ? 'none' : substr(hash('sha256', $token), 0, 8).'/'.strlen($token).'ch';
    }

    /**
     * The `mystore` slug every admin deep link is built from.
     *
     * Rows written before this app started saving `handle` have an empty one, and the myshopify
     * domain always carries the same string — so derive it rather than emit
     * https://admin.shopify.com/store/, which a merchant experiences as "the app is broken".
     */
    public function adminHandle(): string
    {
        $handle = trim((string) $this->handle);

        if ($handle !== '') {
            return $handle;
        }

        return trim((string) explode('.', (string) $this->domain)[0]);
    }

    /**
     * The app handle Shopify reported for THIS installation, cached by
     * BillingService::reportedAppHandle(). Read on every board load to build the plan link, so
     * this must never reach the network — an empty string just means "not verified yet".
     */
    public function shopifyAppHandle(): string
    {
        return strtolower(trim((string) data_get(
            (array) $this->setting('billing.app_handle_check', []), 'handle', ''
        )));
    }

    /** The store's own admin address through its myshopify domain (see shopify.billing.plans_url_style). */
    public function myshopifyAdminUrl(): string
    {
        return 'https://'.strtolower(trim((string) $this->domain)).'/admin';
    }

    /**
     * Is this a partner development shop? Cached, and asked through BillingService on first use —
     * see BillingService::ensureShopPlanFlags(). This decides whether an app-created charge is a
     * test charge, so "unknown" must mean no.
     */
    public function isPartnerDevelopmentStore(?\App\Services\BillingService $service = null): bool
    {
        $held = (array) $this->setting('shopify.plan', []);

        if (array_key_exists('partner_development', $held)) {
            return (bool) $held['partner_development'];
        }

        $service = $service ?: new \App\Services\BillingService($this);

        return (bool) ($service->ensureShopPlanFlags()['partner_development'] ?? false);
    }

    public function adminBaseUrl(): string
    {
        return 'https://admin.shopify.com/store/'.$this->adminHandle();
    }

    /** Deep link that opens this app inside the merchant's admin. */
    public function appUrl(?string $query = null): string
    {
        $url = $this->adminBaseUrl().'/apps/'.config('shopify.api_key');

        return $query ? $url.'?'.$query : $url;
    }
}
