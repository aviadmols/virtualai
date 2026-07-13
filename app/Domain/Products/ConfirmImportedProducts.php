<?php

namespace App\Domain\Products;

use App\Domain\Scan\Review\ConfirmScanAction;
use App\Domain\Scan\Review\ConfirmScanInput;
use App\Domain\Scan\Review\ScanConfirmBlockedException;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * ConfirmImportedProducts — the merchant's "Confirm all N imported" bulk action.
 *
 * THE NO-AUTO-APPROVE LAW IS INTACT. Nothing confirms itself: this runs only when the
 * merchant clicks, and every product still goes through the SAME server-side
 * ConfirmScanAction -> ConfirmGate. An imported product's fields all carry
 * {confidence: 1.0, source: shopify} — the merchant's own store record, nothing guessed
 * — so the gate is already open for them and confirming is friction-free rather than
 * bypassed.
 *
 * A product the gate still blocks (e.g. a Shopify product with no image at all) is
 * SKIPPED and reported, never force-confirmed. `force` is not used here.
 */
final readonly class ConfirmImportedProducts
{
    public function __construct(
        private ConfirmScanAction $confirm,
    ) {}

    /**
     * The imported DRAFT products of this site awaiting the merchant's confirm.
     *
     * @return Collection<int,Product>
     */
    public function pending(Site $site): Collection
    {
        return Product::query()
            ->where('site_id', $site->getKey())
            ->where('source', Product::SOURCE_SHOPIFY)
            ->where('status', Product::STATUS_DRAFT)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    public function pendingCount(Site $site): int
    {
        return $this->pending($site)->count();
    }

    /**
     * Confirm every pending imported product whose gate is open.
     *
     * @return array{confirmed:int, blocked:int}
     */
    public function confirmAll(Site $site): array
    {
        $confirmed = 0;
        $blocked = 0;

        foreach ($this->pending($site) as $product) {
            try {
                $this->confirm->confirm($product, new ConfirmScanInput(
                    fieldValues: [],
                    selectors: [],
                    variants: [],
                    reviewedKeys: [],
                ));

                $confirmed++;
            } catch (ScanConfirmBlockedException) {
                // Still needs a human (a missing image, a broken selector). Never forced.
                $blocked++;
            }
        }

        return ['confirmed' => $confirmed, 'blocked' => $blocked];
    }
}
