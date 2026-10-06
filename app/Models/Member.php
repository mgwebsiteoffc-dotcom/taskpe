<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_STAFF = 'staff';

    protected $fillable = [
        'shop_id', 'name', 'phone', 'role', 'active',
        'whatsapp_verified', 'otp_hash', 'otp_expires_at',
        'portal_token_hash', 'portal_issued_at',
    ];

    protected $casts = [
        'active'            => 'boolean',
        'whatsapp_verified' => 'boolean',
        'otp_expires_at'    => 'datetime',
        'portal_issued_at'  => 'datetime',
    ];

    protected $hidden = ['otp_hash', 'portal_token_hash'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    public function issueOtp(string $plainCode): void
    {
        $this->otp_hash       = hash('sha256', $plainCode);
        $this->otp_expires_at = now()->addMinutes((int) config('whatify.otp_ttl_minutes', 10));
    }

    public function checkOtp(string $plainCode): bool
    {
        if (!$this->otp_hash || !$this->otp_expires_at || $this->otp_expires_at->isPast()) {
            return false;
        }

        return hash_equals($this->otp_hash, hash('sha256', $plainCode));
    }

    /* ------------------------------------------------------ staff portal */

    /** Mint a NEW portal token; every older link for this member dies. */
    public function issuePortalToken(): string
    {
        $raw = \Illuminate\Support\Str::random(48);
        $this->forceFill([
            'portal_token_hash' => hash('sha256', $raw),
            'portal_issued_at'  => now(),
        ])->save();

        return $raw;
    }

    public function revokePortalToken(): void
    {
        $this->forceFill(['portal_token_hash' => null, 'portal_issued_at' => null])->save();
    }

    public static function findByPortalToken(string $raw): ?self
    {
        if (strlen($raw) < 32) {
            return null;
        }

        return static::where('portal_token_hash', hash('sha256', $raw))->first();
    }

    public function portalActive(): bool
    {
        return !empty($this->portal_token_hash);
    }
}
