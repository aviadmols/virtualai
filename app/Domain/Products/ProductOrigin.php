<?php

namespace App\Domain\Products;

use App\Models\Product;

/**
 * ProductOrigin — WHERE a MappedProduct came from, as an immutable identity.
 *
 * The scan rail identifies a product by its PDP url (sha1 hash); the Shopify rail by
 * the Admin API's product GID (`external_id`, unique per site) with the storefront url
 * kept for display + the widget's fallback resolution. PersistProduct reads ONLY this
 * to decide which existing row (if any) a mapped bag belongs to — the two rails never
 * grow their own matching rules.
 *
 * It also carries ONE lifecycle fact the rail knows and the writer must not guess:
 * `platformActive` — does the platform still OFFER this product? A Shopify product whose
 * status is DRAFT/ARCHIVED is not sellable, so it must not be (re-)activated locally.
 */
final readonly class ProductOrigin
{
    // Shopify has no "no handle" state, but a private/unpublished product has no
    // onlineStoreUrl — the caller then synthesises one from the shop + handle.
    private function __construct(
        public string $source,
        public string $sourceUrl,
        public ?string $externalId,
        public ?string $externalHandle,
        public bool $platformActive,
    ) {}

    /** A PDP scan: the merchant-pasted url is the identity. The page exists — it is live. */
    public static function scan(string $url): self
    {
        return new self(Product::SOURCE_SCAN, $url, null, null, platformActive: true);
    }

    /**
     * A Shopify import: the product GID is the identity; the storefront url is data.
     * An empty gid/handle is normalised to NULL — never '' (an empty string is a
     * DISTINCT value that collides in the unique index; NULL is excluded from it).
     *
     * $platformActive is the store's own `status` (ACTIVE vs DRAFT/ARCHIVED). It defaults
     * to TRUE so an absent/unknown status can never archive a product by accident — only
     * an EXPLICIT non-active status deactivates.
     */
    public static function shopify(string $gid, ?string $handle, string $url, bool $platformActive = true): self
    {
        return new self(
            Product::SOURCE_SHOPIFY,
            $url,
            self::nullIfBlank($gid),
            self::nullIfBlank($handle),
            $platformActive,
        );
    }

    public function sourceUrlHash(): string
    {
        return sha1($this->sourceUrl);
    }

    public function isShopify(): bool
    {
        return $this->source === Product::SOURCE_SHOPIFY;
    }

    /** The identity columns written on every persist. */
    public function toAttributes(): array
    {
        return [
            'source' => $this->source,
            'source_url' => $this->sourceUrl,
            'source_url_hash' => $this->sourceUrlHash(),
            'external_id' => $this->externalId,
            'external_handle' => $this->externalHandle,
        ];
    }

    private static function nullIfBlank(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
