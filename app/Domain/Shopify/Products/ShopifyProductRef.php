<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Products\ProductOrigin;

/**
 * ShopifyProductRef — how one Shopify product is identified on the Tray On side:
 * the product GID (the upsert key), its storefront handle, its storefront url, and
 * whether the STORE still offers it (status ACTIVE vs DRAFT/ARCHIVED).
 *
 * Converts straight into the rail-agnostic ProductOrigin the single writer consumes.
 */
final readonly class ShopifyProductRef
{
    public function __construct(
        public string $gid,
        public ?string $handle,
        public string $url,
        public bool $active = true,
    ) {}

    public function toOrigin(): ProductOrigin
    {
        return ProductOrigin::shopify($this->gid, $this->handle, $this->url, $this->active);
    }
}
