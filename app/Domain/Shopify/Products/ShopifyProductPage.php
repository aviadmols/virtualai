<?php

namespace App\Domain\Shopify\Products;

use App\Domain\Scan\Map\MappedProduct;

/**
 * ShopifyProductPage — one cursor page of the catalog walk: the mapped products of the
 * page, plus Shopify's own resume point. `endCursor` is what the sync run persists, so
 * an interrupted walk continues exactly where it stopped instead of re-billing throttle
 * budget on pages it already imported.
 *
 * @phpstan-type Entry array{0: MappedProduct, 1: ShopifyProductRef}
 */
final readonly class ShopifyProductPage
{
    /**
     * @param  array<int,array{0: MappedProduct, 1: ShopifyProductRef}>  $entries
     */
    public function __construct(
        public array $entries,
        public bool $hasNextPage,
        public ?string $endCursor,
    ) {}

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
