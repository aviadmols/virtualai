<?php

namespace App\Domain\Shopify\Media;

use App\Models\Product;
use App\Models\ProductAsset;
use App\Models\ShopifyMediaSnapshot;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PushProductMedia — the merchant's entry point to the store rail: push an approved image,
 * re-push a failed one, undo a product's gallery, and read the gallery the placement chooser
 * shows.
 *
 * It plans and queues; it never calls Shopify to MUTATE anything (the jobs do) and it never
 * touches the ledger (a push is free). Every refusal is a TYPED PushResult, never an exception —
 * a merchant who clicks Push on an image they have not approved yet gets a notification, not a
 * 500.
 *
 * Every read runs through the BelongsToAccount global scope + an explicit site filter, so a
 * foreign account's asset or product simply is not there (fail closed).
 */
final class PushProductMedia
{
    // === CONSTANTS ===
    private const LOG_GALLERY_FAILED = 'shopify.media.gallery_unavailable';

    public function __construct(
        private readonly ShopifyMediaPusher $pusher,
    ) {}

    /** Queue the push of ONE approved image at the merchant's chosen placement. */
    public function push(Site $site, int $assetId, MediaPlacement $placement): PushResult
    {
        $asset = $this->asset($site, $assetId);

        if ($asset === null) {
            return PushResult::denied(PushResult::REASON_NOT_FOUND);
        }

        if (! $asset->isApproved()) {
            return PushResult::denied(PushResult::REASON_NOT_APPROVED);
        }

        if ($asset->isPushed()) {
            return PushResult::denied(PushResult::REASON_ALREADY_PUSHED);
        }

        // In flight — unless it is LOST. A killed worker never calls failed(), and an asset stranded
        // at `pushing` could never be pushed again (this branch AND rePush() would both deny it
        // forever). Past the stuck window the merchant may reclaim it; the pusher RESUMES the
        // persisted media id, so a reclaim can never mint a second copy.
        if ($asset->isPushing() && ! $asset->isPushStuck()) {
            return PushResult::denied(PushResult::REASON_IN_FLIGHT);
        }

        if (! $this->isShopifyProduct($site, (int) $asset->product_id)) {
            return PushResult::denied(PushResult::REASON_NOT_SHOPIFY);
        }

        PushProductMediaJob::dispatch(
            (int) $site->account_id,
            (int) $site->getKey(),
            (int) $asset->getKey(),
            $placement->toArray(),
        );

        return PushResult::queued();
    }

    /**
     * Retry a FAILED push — the PUSH ONLY, at the SAME placement the merchant already chose.
     *
     * It re-runs no AI and costs no credit: the image already exists and was already paid for.
     * (And if the failure happened after Shopify had already minted the media, the pusher resumes
     * from the stored shopify_media_id instead of uploading a second copy.)
     */
    public function rePush(Site $site, int $assetId): PushResult
    {
        $asset = $this->asset($site, $assetId);

        if ($asset === null) {
            return PushResult::denied(PushResult::REASON_NOT_FOUND);
        }

        // A LOST push (a killed worker) is re-pushable too — otherwise that image is stuck forever.
        if (! $asset->isPushFailed() && ! $asset->isPushStuck()) {
            return PushResult::denied($asset->isPushed()
                ? PushResult::REASON_ALREADY_PUSHED
                : PushResult::REASON_IN_FLIGHT);
        }

        return $this->push($site, $assetId, MediaPlacement::fromAsset($asset));
    }

    /** Queue the restore of ONE product's ORIGINAL gallery (order + featured image + bytes). */
    public function undo(Site $site, int $productId): PushResult
    {
        $product = $this->product($site, $productId);

        if ($product === null) {
            return PushResult::denied(PushResult::REASON_NOT_FOUND);
        }

        $snapshot = ShopifyMediaSnapshot::query()
            ->where('product_id', $product->getKey())
            ->where('status', ShopifyMediaSnapshot::STATUS_CAPTURED)
            ->first();

        if ($snapshot === null) {
            return PushResult::denied(PushResult::REASON_NOTHING_TO_UNDO);
        }

        UndoProductMediaJob::dispatch(
            (int) $site->account_id,
            (int) $site->getKey(),
            (int) $product->getKey(),
        );

        return PushResult::queued();
    }

    /**
     * The product's CURRENT Shopify gallery — what the placement chooser renders (so the merchant
     * picks a real slot / a real image to replace, not a number they guessed).
     *
     * A store we cannot reach is an EMPTY gallery, never an exception: the chooser then offers
     * append only, which is the placement that needs no knowledge of the existing gallery.
     *
     * @return array<int,ShopifyMediaItem>
     */
    public function gallery(Site $site, int $productId): array
    {
        $product = $this->product($site, $productId);

        if ($product === null || ! $product->isShopify()) {
            return [];
        }

        try {
            return $this->pusher->gallery($product, $site);
        } catch (Throwable $e) {
            Log::warning(self::LOG_GALLERY_FAILED, [
                'account_id' => (int) $site->account_id,
                'site_id' => (int) $site->getKey(),
                'product_id' => $productId,
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /** True when this product carries a captured original-gallery snapshot (undo is offerable). */
    public function hasSnapshot(Site $site, int $productId): bool
    {
        return ShopifyMediaSnapshot::query()
            ->where('site_id', $site->getKey())
            ->where('product_id', $productId)
            ->where('status', ShopifyMediaSnapshot::STATUS_CAPTURED)
            ->exists();
    }

    /**
     * The products (of this site) that carry a captured snapshot — one query for the whole grid.
     *
     * @return array<int,int>
     */
    public function snapshottedProductIds(Site $site): array
    {
        return ShopifyMediaSnapshot::query()
            ->where('site_id', $site->getKey())
            ->where('status', ShopifyMediaSnapshot::STATUS_CAPTURED)
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function asset(Site $site, int $assetId): ?ProductAsset
    {
        return ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->whereKey($assetId)
            ->first();
    }

    private function product(Site $site, int $productId): ?Product
    {
        return Product::query()
            ->where('site_id', $site->getKey())
            ->whereKey($productId)
            ->first();
    }

    private function isShopifyProduct(Site $site, int $productId): bool
    {
        $product = $this->product($site, $productId);

        return $product !== null
            && $product->isShopify()
            && is_string($product->external_id)
            && $product->external_id !== '';
    }
}
