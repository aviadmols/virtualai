<?php

namespace App\Domain\Shopify\Media;

/**
 * ShopifyMediaItem — ONE entry of a product's Shopify gallery, already parsed.
 *
 * `position` is the merchant-facing 1-BASED slot (1 = the main/featured image), derived from
 * the order Shopify returns. Shopify's own MoveInput is ZERO-based; the conversion happens in
 * exactly one place (ShopifyMediaClient::reorder), so the off-by-one can only exist there.
 */
final readonly class ShopifyMediaItem
{
    // === CONSTANTS ===
    public const STATUS_UPLOADED = 'UPLOADED';

    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_READY = 'READY';

    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        public string $id,
        public string $status,
        public ?string $alt,
        public ?string $imageUrl,
        public int $position,
    ) {}

    /** @param array<string,mixed> $node */
    public static function fromNode(array $node, int $position): self
    {
        $image = (array) ($node['image'] ?? []);

        return new self(
            id: (string) ($node['id'] ?? ''),
            status: strtoupper((string) ($node['status'] ?? '')),
            alt: is_string($node['alt'] ?? null) && $node['alt'] !== '' ? (string) $node['alt'] : null,
            imageUrl: is_string($image['url'] ?? null) && $image['url'] !== '' ? (string) $image['url'] : null,
            position: $position,
        );
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
