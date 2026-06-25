<?php

namespace App\Domain\Credits;

use App\Models\AiOperation;

/**
 * CreditMath — the pure markup/money arithmetic. No I/O, no DB writes, no state.
 *
 * Money is integer micro-USD of SELLING value everywhere. The ONLY rounding the
 * money path does is here, once, at the cost->charge boundary:
 *   charge_micro = round(actual_cost_usd × multiplier × 1_000_000).
 *
 * The multiplier is NEVER a literal at a call site. A caller either passes the
 * resolved multiplier (from AiOperationResolver / operation.credit_multiplier) or
 * uses multiplierFor() which reads operation.credit_multiplier ?? the config
 * default — so changing the markup is a DB/config edit, never a code edit.
 */
final class CreditMath
{
    // === CONSTANTS ===
    // 1 USD = 1,000,000 micro-USD. The single conversion factor.
    public const MICRO_PER_USD = 1_000_000;

    // Where the default markup lives when an operation has no per-op override.
    private const MARKUP_CONFIG_KEY = 'trayon.pricing.markup_default';

    /**
     * The selling value (micro-USD) to charge for a generation that cost
     * $actualCostUsd at $multiplier markup. Rounded once, to the nearest micro-USD.
     */
    public static function chargeMicroUsd(float $actualCostUsd, float $multiplier): int
    {
        return (int) round($actualCostUsd * $multiplier * self::MICRO_PER_USD);
    }

    /** USD (float) -> integer micro-USD. Rounds to the nearest micro-USD. */
    public static function usdToMicro(float $usd): int
    {
        return (int) round($usd * self::MICRO_PER_USD);
    }

    /** Integer micro-USD -> USD (float). For display / reporting only. */
    public static function microToUsd(int $micro): float
    {
        return $micro / self::MICRO_PER_USD;
    }

    /**
     * The resolved markup multiplier for an operation: the per-operation
     * credit_multiplier when set, else the config default. NEVER a literal — this
     * is the one sanctioned place the default is read.
     */
    public static function multiplierFor(AiOperation|string $operation): float
    {
        $perOperation = $operation instanceof AiOperation
            ? $operation->credit_multiplier
            : AiOperation::query()->where('operation_key', $operation)->value('credit_multiplier');

        if ($perOperation !== null) {
            return (float) $perOperation;
        }

        return (float) config(self::MARKUP_CONFIG_KEY);
    }
}
