<?php

namespace App\Domain\Shopify\Api;

/**
 * ShopProfile — the store's public identity read from the Admin API at install time
 * (name + a usable contact email). Both fields are nullable: a store may not expose an
 * email, or the read may fail, in which case auto-provisioning falls back to a
 * deterministic shop-derived login.
 */
final class ShopProfile
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
    ) {}

    /** The empty profile — nothing was read; the caller falls back to shop-derived values. */
    public static function empty(): self
    {
        return new self(name: null, email: null);
    }
}
