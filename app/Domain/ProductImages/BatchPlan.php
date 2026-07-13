<?php

namespace App\Domain\ProductImages;

/**
 * BatchPlan — the ADVISORY pre-flight the merchant sees BEFORE confirming a batch:
 * "N images, about $X, and you can (or cannot) afford it".
 *
 * Advisory is the operative word. It authorises nothing: the authoritative money path runs
 * per asset on the worker (CreditGate -> reserve -> provider -> charge ONLY on success). The
 * plan exists so a merchant is never surprised by the bill, and so a batch that obviously
 * cannot be paid for is refused up front instead of queueing N assets that each cancel.
 */
final readonly class BatchPlan
{
    /**
     * @param  list<int>  $eligibleProductIds  products that have the chosen source photo
     * @param  list<int>  $skippedProductIds  products with nothing in that slot
     */
    public function __construct(
        public array $eligibleProductIds,
        public array $skippedProductIds,
        public int $estimatePerAssetMicroUsd,
        public int $spendableMicroUsd,
    ) {}

    public function count(): int
    {
        return count($this->eligibleProductIds);
    }

    /** The advisory total: the per-asset estimate × how many assets would actually run. */
    public function totalMicroUsd(): int
    {
        return $this->estimatePerAssetMicroUsd * $this->count();
    }

    /** True when the account's spendable credit covers the whole batch estimate. */
    public function affordable(): bool
    {
        return $this->count() > 0 && $this->spendableMicroUsd >= $this->totalMicroUsd();
    }

    /** How many images the current balance can afford (what the UI offers to run instead). */
    public function affordableCount(): int
    {
        if ($this->estimatePerAssetMicroUsd <= 0) {
            return $this->count();
        }

        return (int) min($this->count(), intdiv(max(0, $this->spendableMicroUsd), $this->estimatePerAssetMicroUsd));
    }
}
