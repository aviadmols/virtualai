<?php

namespace App\Domain\Shopify\Auth;

use App\Models\User;

/**
 * ShopifyProvisionResult — what the OAuth callback needs after a Shopify-SSO
 * auto-provision: the owner User to log in, and the Site id to land the merchant on in
 * the panel. A value object so the callback never re-queries either.
 */
final class ShopifyProvisionResult
{
    public function __construct(
        public readonly User $owner,
        public readonly int $siteId,
    ) {}
}
