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

    public function isInstalled(): bool
    {
        return is_null($this->uninstalled_at) && !empty($this->access_token);
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
