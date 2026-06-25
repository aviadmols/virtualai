<?php

namespace App\Domain\Generation;

use App\Domain\Ai\OperationConfig;
use App\Domain\Credits\CreditMath;

/**
 * CreditEstimator — the MAX selling value (micro-USD) to reserve before a try-on.
 *
 * Reserve the worst case, charge the real cost. The estimate is the operation's
 * estimated_cost_usd × the resolved markup multiplier — both DB/config-driven via
 * the resolver bag, never a literal here. The reservation holds this much so two
 * concurrent generations can't both pass the gate against one balance; the actual
 * charge (computed from the real OpenRouter cost) is almost always smaller and the
 * difference is released.
 *
 * A floor guards the case where the operation has no estimate row, so a reservation
 * is never zero (which would let an unbounded number of in-flight generations pass).
 */
final class CreditEstimator
{
    // === CONSTANTS ===
    // Fallback estimate when the operation carries no estimated cost (micro-USD of
    // SELLING value). $0.25 — a conservative non-zero hold; config can supersede later.
    private const FALLBACK_ESTIMATE_MICRO_USD = 250_000;

    /**
     * The micro-USD to reserve for a generation under this resolved config. Uses the
     * operation's estimated cost × the resolved multiplier; the multiplier defaults
     * to the config markup when the operation has none (never a literal at this site).
     */
    public function estimateMicroUsd(OperationConfig $config): int
    {
        $estimatedCostMicroUsd = $config->estimatedCostMicroUsd;

        if ($estimatedCostMicroUsd === null || $estimatedCostMicroUsd <= 0) {
            return self::FALLBACK_ESTIMATE_MICRO_USD;
        }

        $multiplier = $config->creditMultiplier ?? CreditMath::multiplierFor($config->operationKey);

        $estimate = (int) round($estimatedCostMicroUsd * $multiplier);

        return max($estimate, self::FALLBACK_ESTIMATE_MICRO_USD);
    }
}
