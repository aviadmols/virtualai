<?php

namespace App\Domain\ProductImages;

use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;

/**
 * FixProductImage — an image-to-image correction of a finished result.
 *
 * Unlike Regenerate (which re-runs from the ORIGINAL product photo), Fix feeds the CURRENT
 * generated RESULT back into the same edit model, guided by the merchant's correction
 * instruction. So the source identity is the result asset itself — hashed from its STABLE
 * (id, image_path), never an expiring signed URL — so a double-clicked fix collapses to one
 * charge and a deliberate re-fix varies deterministically, exactly like Regenerate. The
 * correction rides in the note (extra['notes']); the idempotency `extra` shape is UNCHANGED.
 *
 * A fix needs REAL result bytes to edit, so only a SUCCEEDED asset with a stored image_path
 * qualifies (a failed / cancelled / still-rendering asset has nothing to fix) — a typed denial,
 * never a 500.
 */
final readonly class FixProductImage
{
    // === CONSTANTS ===
    private const INTENT_PREFIX = ProductAsset::REQUEST_FIX_PREFIX;

    private const INTENT_SEPARATOR = '-';

    public function __construct(
        private StartProductImageBatch $batches,
    ) {}

    public function handle(Site $site, int $sourceAssetId, string $instruction): BatchResult
    {
        $source = $this->asset($site, $sourceAssetId);
        $instruction = trim($instruction);

        // Nothing to fix: an unknown/foreign asset, one without a stored result, or an empty
        // instruction. All are the same typed "nothing to do" — no asset, no charge.
        if ($source === null
            || ! $source->isSucceeded()
            || $source->image_path === null || $source->image_path === ''
            || $instruction === '') {
            return BatchResult::deniedUnplanned(BatchResult::DENIED_NOTHING_TO_DO);
        }

        return $this->batches->handle(
            site: $site,
            productIds: [(int) $source->product_id],
            operationKey: (string) $source->operation_key,
            sourcePick: ProductImageBatch::SOURCE_RESULT, // source = the RESULT, not a product photo
            clientRequestId: $this->intentId($source),
            sourceAssetId: (int) $source->getKey(),
            // Keep the source's look; the correction rides in the note (appended to the prompt).
            styleId: $source->style_preset_id !== null ? (int) $source->style_preset_id : null,
            notes: $instruction,
            aspectRatio: $source->batch?->aspect_ratio,
            imageQuality: $source->batch?->image_quality,
        );
    }

    /**
     * The DETERMINISTIC client_request_id of "fix this asset": fix-{source}-{settled}. It moves
     * only when a child of this source SETTLES — never on a click — so a double-click collapses on
     * the same key and a deliberate later fix mints a new, separately-charged asset. The fix-/regen-
     * prefixes plus the differing source hash keep fix and regenerate keys disjoint.
     */
    public function intentId(ProductAsset $source): string
    {
        $settled = ProductAsset::query()
            ->where('source_asset_id', $source->getKey())
            ->whereIn('status', ProductAsset::TERMINAL_STATUSES)
            ->count();

        return self::INTENT_PREFIX.$source->getKey().self::INTENT_SEPARATOR.$settled;
    }

    /** The asset inside THIS shop (global scope + explicit site filter — fail closed). */
    private function asset(Site $site, int $assetId): ?ProductAsset
    {
        return ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->whereKey($assetId)
            ->first();
    }
}
