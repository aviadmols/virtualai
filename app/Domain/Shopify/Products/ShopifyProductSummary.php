<?php

namespace App\Domain\Shopify\Products;

/**
 * ShopifyProductSummary — the light shape the merchant's product PICKER renders (id,
 * title, handle, thumbnail). Deliberately variant-free: the picker must stay cheap on
 * Shopify's cost budget; the full product is only fetched when it is actually imported.
 */
final readonly class ShopifyProductSummary
{
    public function __construct(
        public string $gid,
        public string $title,
        public ?string $handle,
        public ?string $imageUrl,
        public ?string $status,
    ) {}

    /** @param array<string,mixed> $node */
    public static function fromNode(array $node): self
    {
        return new self(
            gid: (string) ($node['id'] ?? ''),
            title: (string) ($node['title'] ?? ''),
            handle: isset($node['handle']) ? (string) $node['handle'] : null,
            imageUrl: isset($node['featuredImage']['url']) ? (string) $node['featuredImage']['url'] : null,
            status: isset($node['status']) ? (string) $node['status'] : null,
        );
    }
}
