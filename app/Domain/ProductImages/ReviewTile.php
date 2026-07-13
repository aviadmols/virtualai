<?php

namespace App\Domain\ProductImages;

use App\Domain\Media\MediaStorage;
use App\Models\Product;
use App\Models\ProductAsset;

/**
 * ReviewTile — ONE cell of the review grid, already resolved for rendering.
 *
 * The Blade view stays dumb: it never signs a URL, never reads a model, never formats money.
 * The image ref stays an opaque PRIVATE disk path on the row; the tile carries only a
 * short-lived SIGNED url (config TTL), so nothing hot-linkable is ever embedded.
 *
 * The tile also carries the STORE state (Phase 5): whether this image is in the merchant's
 * Shopify gallery, what went wrong if it is not (Shopify's own mediaUserErrors, verbatim), and
 * whether the product has a captured original-gallery snapshot — which is what makes the
 * per-product "Restore original images" action offerable.
 */
final readonly class ReviewTile
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $productName,
        public ?string $imageUrl,
        public string $reviewStatus,
        public ?string $modelUsed,
        public int $chargeMicroUsd,
        public string $pushStatus,
        public ?string $pushError,
        public ?string $shopifyMediaId,
        public bool $isShopifyProduct,
        public bool $hasSnapshot,
    ) {}

    /** @param array<int,int> $snapshottedProductIds products whose originals we already hold */
    public static function from(ProductAsset $asset, MediaStorage $media, array $snapshottedProductIds = []): self
    {
        $product = $asset->product;
        $productId = (int) $asset->product_id;

        return new self(
            id: (int) $asset->getKey(),
            productId: $productId,
            productName: (string) ($product?->name ?? ''),
            imageUrl: $media->signedUrl($asset->image_path),
            reviewStatus: (string) $asset->review_status,
            modelUsed: $asset->model_used,
            chargeMicroUsd: (int) ($asset->charge_micro_usd ?? 0),
            pushStatus: (string) ($asset->push_status ?? ProductAsset::PUSH_NOT_PUSHED),
            pushError: $asset->push_error,
            shopifyMediaId: $asset->shopify_media_id,
            isShopifyProduct: $product instanceof Product && $product->isShopify() && (string) $product->external_id !== '',
            hasSnapshot: in_array($productId, $snapshottedProductIds, true),
        );
    }

    public function isApproved(): bool
    {
        return $this->reviewStatus === ProductAsset::REVIEW_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->reviewStatus === ProductAsset::REVIEW_REJECTED;
    }

    public function isPushed(): bool
    {
        return $this->pushStatus === ProductAsset::PUSH_PUSHED;
    }

    public function isPushing(): bool
    {
        return $this->pushStatus === ProductAsset::PUSH_PUSHING;
    }

    public function isPushFailed(): bool
    {
        return $this->pushStatus === ProductAsset::PUSH_FAILED;
    }

    /** Only an APPROVED image of a SHOPIFY product that is not already in the store can be pushed. */
    public function canPush(): bool
    {
        return $this->isApproved()
            && $this->isShopifyProduct
            && $this->pushStatus === ProductAsset::PUSH_NOT_PUSHED;
    }

    /** A re-push retries the UPLOAD ONLY — it never re-runs the AI and is never charged. */
    public function canRePush(): bool
    {
        return $this->isApproved() && $this->isShopifyProduct && $this->isPushFailed();
    }

    /** Undo is offerable only once we actually hold this product's original images. */
    public function canUndo(): bool
    {
        return $this->isShopifyProduct && $this->hasSnapshot;
    }
}
