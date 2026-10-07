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
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'whatify_api_key'  => 'encrypted',
        'settings'         => 'array',
        'installed_at'     => 'datetime',
        'uninstalled_at'   => 'datetime',
        'redacted_at'      => 'datetime',
    ];

    protected $hidden = ['access_token', 'whatify_api_key'];

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

    public function adminBaseUrl(): string
    {
        return "https://admin.shopify.com/store/{$this->handle}";
    }

    /** Deep link that opens this app inside the merchant's admin. */
    public function appUrl(?string $query = null): string
    {
        $url = $this->adminBaseUrl().'/apps/'.config('shopify.api_key');

        return $query ? $url.'?'.$query : $url;
    }
}
