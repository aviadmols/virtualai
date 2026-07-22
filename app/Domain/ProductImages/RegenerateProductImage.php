<?php

namespace App\Domain\ProductImages;

use App\Models\ProductAsset;
use App\Models\ProductImageBatch;
use App\Models\Site;

/**
 * RegenerateProductImage — "run this image again", and the ONLY place that decides what a
 * regenerate's client_request_id is.
 *
 * WHY THIS CLASS EXISTS (the scar it closes). Regenerate is the one deliberate exception to the
 * batch rail: it is MEANT to vary the deterministic asset key, so the AI runs — and charges —
 * again. But "vary per CLICK" and "vary per INTENT" are not the same thing, and the difference
 * is a double charge: a client_request_id minted from a random value at click time makes a
 * double-clicked button produce TWO assets, TWO provider renders and TWO charge rows. The money
 * guard cannot live in the button (a disabled button is a hint, not a wall).
 *
 * So the intent id is DERIVED, deterministically, from the merchant's actual intent:
 *
 *     regen-{source_asset_id}-{n}     n = regenerations of that source that have SETTLED
 *
 * Two clicks of the same button, milliseconds apart, both read the same n (the render they just
 * started has not settled — it is not even terminal), hash to the SAME idempotency key, and the
 * second collides on the UNIQUE index: one asset, one render, one charge. The merchant's NEXT
 * regenerate — asked for after seeing the result, i.e. once that render settled — reads n+1 and
 * correctly mints a new, separately-charged asset. Deterministic, never random.
 *
 * And a regenerate of an image that is still rendering is refused outright (typed, never a 500):
 * the render the merchant is waiting for is the one already in flight.
 */
final readonly class RegenerateProductImage
{
    // === CONSTANTS ===
    private const INTENT_PREFIX = ProductAsset::REQUEST_REGENERATE_PREFIX;

    private const INTENT_SEPARATOR = '-';

    public function __construct(
        private StartProductImageBatch $batches,
    ) {}

    /**
     * Regenerate ONE finished asset. Returns the SAME typed outcome a batch returns, so the
     * studio renders it with the same notifications (a denial is never an exception).
     *
     * $noteOverride null → reproduce the SAME look (today's behaviour). A string → "Update prompt":
     * regenerate from the ORIGINAL product photo with the merchant's edited art-direction note
     * REPLACING the source's. A changed note is folded into the idempotency key (extra['notes']),
     * so it is a genuinely new image — never skipped as a duplicate; an unchanged note collapses on
     * the same intent id like any double-click.
     */
    public function handle(Site $site, int $sourceAssetId, ?string $noteOverride = null): BatchResult
    {
        $source = $this->asset($site, $sourceAssetId);

        if ($source === null) {
            return BatchResult::deniedUnplanned(BatchResult::DENIED_NOTHING_TO_DO);
        }

        // Its render has not settled: there is nothing to regenerate yet, and queuing a second
        // render of the same image would pay twice for one image.
        if (! $source->isTerminal()) {
            return BatchResult::deniedUnplanned(BatchResult::DENIED_STILL_RENDERING);
        }

        return $this->batches->handle(
            site: $site,
            productIds: [(int) $source->product_id],
            operationKey: (string) $source->operation_key,
            sourcePick: (string) ($source->batch?->source_pick ?? ProductImageBatch::SOURCE_MAIN),
            clientRequestId: $this->intentId($source),
            sourceAssetId: (int) $source->getKey(),
            // Reproduce the SAME look: carry the source's style + the batch's ratio/quality. The note
            // is the merchant's edit when "Update prompt" supplied one, else the source's note.
            styleId: $source->style_preset_id !== null ? (int) $source->style_preset_id : null,
            notes: $noteOverride ?? $source->batch?->notes,
            aspectRatio: $source->batch?->aspect_ratio,
            imageQuality: $source->batch?->image_quality,
        );
    }

    /**
     * The DETERMINISTIC client_request_id of "regenerate this asset": regen-{source}-{settled}.
     *
     * It moves only when a regeneration of this source SETTLES — never on a click. That is the
     * whole guard: an accidental repeat sees an unchanged n and collapses onto the same key,
     * while a deliberate later ask (after the previous one finished) sees a new n.
     */
    public function intentId(ProductAsset $source): string
    {
        $settled = ProductAsset::query()
            ->where('source_asset_id', $source->getKey())
            ->whereIn('status', ProductAsset::TERMINAL_STATUSES)
            ->count();

        return self::INTENT_PREFIX.$source->getKey().self::INTENT_SEPARATOR.$settled;
    }

    /** The asset, inside THIS shop (global scope + explicit site filter — fail closed). */
    private function asset(Site $site, int $assetId): ?ProductAsset
    {
        return ProductAsset::query()
            ->where('site_id', $site->getKey())
            ->whereKey($assetId)
            ->first();
    }
}
