<?php

namespace App\Domain\Shopify\Products;

use App\Models\ShopifySyncRun;

/**
 * StartSyncResult — what asking for an import actually DID. A typed result, never an
 * exception: a refused import is a normal outcome the merchant's page renders as a
 * message, not a 500.
 *
 * Three outcomes, and the caller cannot confuse them:
 *  - STARTED: a run was opened and the work is queued;
 *  - JOINED:  a walk was already in flight; this click joined it (nothing new queued);
 *  - REFUSED: NOTHING was opened and NOTHING was dispatched. `reason` says why, and the
 *    UI renders reasonKey() with `catalogSize` + `cap`.
 *
 * A refusal carries no run BY CONSTRUCTION (run is null), so "refused but queued anyway"
 * is not a representable state.
 */
final readonly class StartSyncResult
{
    // === OUTCOMES ===
    public const OUTCOME_STARTED = 'started';

    public const OUTCOME_JOINED = 'joined';

    public const OUTCOME_REFUSED = 'refused';

    // === REFUSAL REASONS ===
    // The store holds more products than one "import all" may take (the platform soft cap).
    public const REASON_OVER_CAP = 'over_cap';

    // Shopify would not tell us how big the catalog is. We refuse rather than walk blind:
    // the cap exists precisely to stop an unmeasured catalog entering the bulk queue.
    public const REASON_SIZE_UNAVAILABLE = 'size_unavailable';

    // The i18n key the page renders for a refusal (design-tokens / i18n catalog).
    private const REASON_KEY_PREFIX = 'shopify.products.refused.';

    private function __construct(
        public string $outcome,
        public ?ShopifySyncRun $run = null,
        public ?string $reason = null,
        public ?int $catalogSize = null,
        public ?int $cap = null,
        // A SELECTION import bounded by selection_max: how many picks were NOT imported. The
        // slice is REPORTED (the page says so, the run is marked truncated), never swallowed.
        public int $dropped = 0,
    ) {}

    public static function started(ShopifySyncRun $run, int $dropped = 0, ?int $cap = null): self
    {
        return new self(outcome: self::OUTCOME_STARTED, run: $run, cap: $cap, dropped: $dropped);
    }

    /** True when the bound left some of the merchant's picks out of this run. */
    public function wasTruncated(): bool
    {
        return $this->dropped > 0;
    }

    public static function joined(ShopifySyncRun $run): self
    {
        return new self(outcome: self::OUTCOME_JOINED, run: $run);
    }

    public static function refusedOverCap(int $catalogSize, int $cap): self
    {
        return new self(
            outcome: self::OUTCOME_REFUSED,
            reason: self::REASON_OVER_CAP,
            catalogSize: $catalogSize,
            cap: $cap,
        );
    }

    public static function refusedSizeUnavailable(int $cap): self
    {
        return new self(
            outcome: self::OUTCOME_REFUSED,
            reason: self::REASON_SIZE_UNAVAILABLE,
            cap: $cap,
        );
    }

    public function refused(): bool
    {
        return $this->outcome === self::OUTCOME_REFUSED;
    }

    /** The i18n key of the merchant-facing refusal message (null when nothing was refused). */
    public function reasonKey(): ?string
    {
        return $this->refused() ? self::REASON_KEY_PREFIX.$this->reason : null;
    }
}
