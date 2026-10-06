<?php

namespace App\Support;

use App\Models\Member;
use App\Models\Shop;

/**
 * Per-request tenant holder, populated by VerifyShopifySessionToken.
 * Registered as a singleton; container resets it between requests/jobs.
 */
class ShopContext
{
    protected ?Shop $shop = null;

    protected ?Member $actor = null;

    protected array $tokenPayload = [];

    public function set(Shop $shop, array $tokenPayload = [], ?Member $actor = null): void
    {
        $this->shop = $shop;
        $this->tokenPayload = $tokenPayload;
        $this->actor = $actor;
    }

    public function shop(): Shop
    {
        if (!$this->shop) {
            throw new \RuntimeException('No shop in context — missing shopify.token middleware?');
        }

        return $this->shop;
    }

    public function id(): int
    {
        return $this->shop()->id;
    }

    public function actor(): ?Member
    {
        return $this->actor;
    }

    /** Display name used on activity entries. */
    public function actorName(): string
    {
        return $this->actor?->name ?? 'Store team';
    }
}
