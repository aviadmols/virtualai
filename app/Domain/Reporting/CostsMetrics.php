<?php

namespace App\Domain\Reporting;

use App\Domain\Credits\CreditMath;

/**
 * CostsMetrics — the immutable PLATFORM-WIDE cost-vs-revenue snapshot for the
 * Super-Admin costs view (component-inventory A12). NOT account-scoped: this is the
 * platform's margin across ALL accounts, only ever built by a super-admin surface.
 *
 * The source of truth is the `charge` ledger rows (credit_ledger.type = charge), the
 * only place real money is reconciled:
 *   - revenueMicroUsd      = Σ |amount_micro_usd| on charges  (selling value billed to merchants)
 *   - actualCostMicroUsd   = Σ actual_cost_micro_usd          (the platform's real OpenRouter spend)
 *   - grossMarginMicroUsd  = revenue − cost                    (what the platform keeps)
 *   - markupRealized       = revenue / cost                    (the EFFECTIVE multiplier, vs the 2.5 target)
 *
 * All money is integer micro-USD; markupRealized is the one ratio (revenue/cost),
 * computed from the integer sums, NOT a stored constant — it shows the multiplier
 * actually realized, which the configured CREDIT_MARKUP_DEFAULT only targets.
 */
final readonly class CostsMetrics
{
    // === CONSTANTS ===
    // KPI keys for the A12 costs view (platform.costs.*). Stable tokens, not i18n.
    public const KPI_REVENUE = 'revenue_micro_usd';
    public const KPI_COST = 'actual_cost_micro_usd';
    public const KPI_MARGIN = 'gross_margin_micro_usd';
    public const KPI_MARKUP_REALIZED = 'markup_realized';
    public const KPI_CHARGES = 'charge_count';

    public function __construct(
        // Σ of selling value billed on charge rows (positive micro-USD).
        public int $revenueMicroUsd,
        // Σ of the platform's real OpenRouter spend behind those charges (positive micro-USD).
        public int $actualCostMicroUsd,
        // revenue − cost (the platform's gross take; may be 0 when cost data is absent).
        public int $grossMarginMicroUsd,
        // revenue / cost (the effective markup), 0.0 when there is no recorded cost.
        public float $markupRealized,
        // How many charge rows backed these figures (the A12 throughput count).
        public int $chargeCount,
        // The configured TARGET markup (CREDIT_MARKUP_DEFAULT) for comparison in the UI.
        public float $markupTarget,
        // The window the figures were computed over (for the report header).
        public MetricWindow $window,
    ) {}

    /** True when there were charges with recorded cost (markupRealized is meaningful). */
    public function hasCostData(): bool
    {
        return $this->actualCostMicroUsd > 0;
    }

    /** Gross margin as a fraction of revenue in [0,1], 0.0 when no revenue. */
    public function marginRatio(): float
    {
        if ($this->revenueMicroUsd === 0) {
            return 0.0;
        }

        return $this->grossMarginMicroUsd / $this->revenueMicroUsd;
    }

    /** Revenue in USD (float) for display only; the integer micro-USD is the truth. */
    public function revenueUsd(): float
    {
        return CreditMath::microToUsd($this->revenueMicroUsd);
    }

    /** Actual cost in USD (float) for display only. */
    public function actualCostUsd(): float
    {
        return CreditMath::microToUsd($this->actualCostMicroUsd);
    }
}
