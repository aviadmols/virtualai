<?php

namespace App\Domain\Products;

use App\Domain\Scan\Map\MappedProduct;
use App\Models\Site;

/**
 * ProductSource — a rail that can produce a MappedProduct for a site.
 *
 * The PDP scanner (fetch + AI extraction) and the Shopify Admin API are two
 * implementations of the same contract, so PersistProduct — the single writer — never
 * learns where a bag came from. A future platform (WooCommerce, custom feed) adds a
 * source, not a second persist path.
 */
interface ProductSource
{
    /**
     * Fetch + map ONE product identified by this rail's own reference (a PDP url for
     * the scanner, a product GID for Shopify).
     *
     * @return array{0: MappedProduct, 1: ProductOrigin}
     */
    public function fetch(Site $site, string $reference): array;
}
