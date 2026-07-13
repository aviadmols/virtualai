<?php

namespace App\Domain\ProductImages;

use App\Models\ProductImageBatch;

/**
 * BatchResult — the TYPED outcome of asking for a batch.
 *
 * A credit denial is a RESULT, never an exception and never a 500: the studio renders an
 * "out of credits" panel with the numbers. A started batch reports how many assets it queued,
 * how many products were skipped for lack of the chosen photo, and how many were skipped
 * because that exact image ALREADY exists (the deterministic key already produced it — a
 * re-run must never regenerate or re-charge it).
 */
final readonly class BatchResult
{
    // === CONSTANTS ===
    public const DENIED_INSUFFICIENT_CREDITS = 'insufficient_credits';

    public const DENIED_ACCOUNT_INACTIVE = 'account_inactive';

    public const DENIED_NOTHING_TO_DO = 'nothing_to_do';

    // A regenerate asked for while the previous render of that same image is still in flight.
    // Nothing is queued and nothing is charged: the render the merchant is waiting for is the
    // one already running (RegenerateProductImage).
    public const DENIED_STILL_RENDERING = 'still_rendering';

    private function __construct(
        public ?ProductImageBatch $batch,
        public int $queued = 0,
        public int $skippedNoImage = 0,
        public int $skippedExisting = 0,
        public ?string $deniedReason = null,
        public ?BatchPlan $plan = null,
    ) {}

    public static function started(
        ProductImageBatch $batch,
        int $queued,
        int $skippedNoImage,
        int $skippedExisting,
    ): self {
        return new self($batch, $queued, $skippedNoImage, $skippedExisting);
    }

    public static function denied(string $reason, BatchPlan $plan): self
    {
        return new self(null, deniedReason: $reason, plan: $plan);
    }

    /**
     * A denial decided BEFORE a plan exists (the regenerate rail: the asset is gone, or its
     * previous render has not settled yet). Nothing was planned, queued or charged.
     */
    public static function deniedUnplanned(string $reason): self
    {
        return new self(null, deniedReason: $reason);
    }

    public function wasDenied(): bool
    {
        return $this->deniedReason !== null;
    }

    public function skipped(): int
    {
        return $this->skippedNoImage + $this->skippedExisting;
    }
}
