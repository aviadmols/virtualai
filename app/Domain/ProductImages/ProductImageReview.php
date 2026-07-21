<?php

namespace App\Domain\ProductImages;

use App\Domain\Media\MediaStorage;
use App\Models\ProductAsset;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * ProductImageReview — the merchant's judgement on generated images: approve / reject, one at a
 * time or in bulk.
 *
 * It is EDITORIAL, not financial. A rejection reverses nothing: the AI ran, the provider billed
 * us, and the charge row stands (the studio states this before a batch starts). Approving is
 * what makes an image eligible for the Phase-5 push to the store's product media.
 *
 * Every read here goes through the BelongsToAccount global scope + an explicit site filter, so a
 * foreign account's asset simply is not there (fail closed) — the review grid can never show, let
 * alone mutate, another tenant's image. Every write goes through the guarded review machine on
 * the model, which refuses to judge an asset that never produced an image and writes an activity
 * event on every accepted move.
 */
final class ProductImageReview
{
    // === CONSTANTS ===
    // Bulk safety valve: one action never touches an unbounded number of rows.
    private const BULK_LIMIT = 500;

    /** Approve ONE asset (site-scoped; a foreign id is simply not found). */
    public function approve(Site $site, int $assetId): bool
    {
        return $this->judge($site, $assetId, ProductAsset::REVIEW_APPROVED);
    }

    /** Reject ONE asset. It does NOT refund — the generation already happened and was charged. */
    public function reject(Site $site, int $assetId): bool
    {
        return $this->judge($site, $assetId, ProductAsset::REVIEW_REJECTED);
    }

    /**
     * True when this asset is LIVE in the store, so it cannot be rejected — the merchant must undo
     * the push first (that is the action that actually takes it off the storefront). The page turns
     * this into a plain explanation instead of a 500 or a silent no-op.
     */
    public function isBlockedByStore(Site $site, int $assetId): bool
    {
        return $this->asset($site, $assetId)?->isInStore() === true;
    }

    /** Approve every image still awaiting review in this batch (or the whole site). */
    public function approveAwaiting(Site $site, ?int $batchId = null): int
    {
        return $this->judgeMany($site, ProductAsset::REVIEW_APPROVED, $batchId);
    }

    /** Reject every image still awaiting review in this batch (or the whole site). */
    public function rejectAwaiting(Site $site, ?int $batchId = null): int
    {
        return $this->judgeMany($site, ProductAsset::REVIEW_REJECTED, $batchId);
    }

    /**
     * Delete ONE finished image for good — remove its media file, then the row. This is EDITORIAL
     * cleanup, NOT a refund: the AI already ran and was charged, and deleting the asset changes
     * nothing about that. Refused for an image that is LIVE in the store (undo the push first) and
     * for anything not yet terminal (an in-flight asset still holds a live reservation).
     */
    public function delete(Site $site, int $assetId): bool
    {
        $asset = $this->asset($site, $assetId);

        if ($asset === null || ! $asset->isTerminal() || $asset->isInStore()) {
            return false;
        }

        app(MediaStorage::class)->delete($asset->image_path);
        $asset->delete();

        return true;
    }

    /**
     * The in-flight assets (queued or rendering) for the live "in progress" strip — so the merchant
     * sees a product IS being worked on, not just a batch counter. Newest first.
     *
     * @return Collection<int,ProductAsset>
     */
    public function processing(Site $site, int $limit = 60): Collection
    {
        return ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->whereIn('status', [ProductAsset::STATUS_PENDING, ProductAsset::STATUS_PROCESSING])
            ->with('product')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * The review grid: the site's finished images, newest first. Optionally narrowed to one
     * batch and/or one review state.
     *
     * @return Collection<int,ProductAsset>
     */
    public function grid(Site $site, ?int $batchId = null, ?string $reviewStatus = null, int $limit = 60): Collection
    {
        $query = ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->where('status', ProductAsset::STATUS_SUCCEEDED)
            ->with('product')
            ->latest('id')
            ->limit($limit);

        if ($batchId !== null) {
            $query->where('batch_id', $batchId);
        }

        if ($reviewStatus !== null) {
            $query->where('review_status', $reviewStatus);
        }

        return $query->get();
    }

    /** Per-state counts for the grid's filter chips. @return array<string,int> */
    public function counts(Site $site, ?int $batchId = null): array
    {
        $base = fn () => ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId));

        return [
            ProductAsset::REVIEW_AWAITING => (clone $base())->where('status', ProductAsset::STATUS_SUCCEEDED)
                ->where('review_status', ProductAsset::REVIEW_AWAITING)->count(),
            ProductAsset::REVIEW_APPROVED => (clone $base())->where('status', ProductAsset::STATUS_SUCCEEDED)
                ->where('review_status', ProductAsset::REVIEW_APPROVED)->count(),
            ProductAsset::REVIEW_REJECTED => (clone $base())->where('status', ProductAsset::STATUS_SUCCEEDED)
                ->where('review_status', ProductAsset::REVIEW_REJECTED)->count(),
            ProductAsset::STATUS_FAILED => (clone $base())->whereIn('status', [
                ProductAsset::STATUS_FAILED,
                ProductAsset::STATUS_CANCELLED,
            ])->count(),
        ];
    }

    /**
     * One guarded review move. False when the asset is not this site's, not reviewable, already
     * there — or LIVE IN THE STORE and about to be rejected (the two machines must agree: an image
     * a shopper can still see is not a rejected image; undo the push first).
     */
    private function judge(Site $site, int $assetId, string $next): bool
    {
        $asset = $this->asset($site, $assetId);

        if ($asset === null || ! $asset->isSucceeded() || $asset->review_status === $next) {
            return false;
        }

        if ($next === ProductAsset::REVIEW_REJECTED && $asset->isInStore()) {
            return false; // the model guard would throw; the merchant gets an explanation instead
        }

        $asset->reviewTransitionTo($next);

        return true;
    }

    /** One of this site's assets (global scope + explicit site filter — fail closed). */
    private function asset(Site $site, int $assetId): ?ProductAsset
    {
        return ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->whereKey($assetId)
            ->first();
    }

    /** Bulk judgement over everything still awaiting review. Returns how many moved. */
    private function judgeMany(Site $site, string $next, ?int $batchId): int
    {
        $assets = ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->where('status', ProductAsset::STATUS_SUCCEEDED)
            ->where('review_status', ProductAsset::REVIEW_AWAITING)
            ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId))
            ->limit(self::BULK_LIMIT)
            ->get();

        foreach ($assets as $asset) {
            $asset->reviewTransitionTo($next);
        }

        return $assets->count();
    }
}
