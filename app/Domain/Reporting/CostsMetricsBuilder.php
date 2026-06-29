<?php

namespace App\Domain\Reporting;

use App\Models\CreditLedger;
use Illuminate\Support\Facades\DB;

/**
 * CostsMetricsBuilder — computes the PLATFORM-WIDE costs-vs-revenue snapshot for the
 * Super-Admin A12 costs view. This is the ONE legitimate cross-account aggregate, and
 * it is built the audited way (mirrors PurchaseRouter, TS-CREDITS-004):
 *
 *   - It uses a single DB::table('credit_ledger') AGGREGATE query that returns only
 *     SUMS + a COUNT — never a hydrated model, never a row, never PII. So it does NOT
 *     trip the BelongsToAccount fail-closed scope, and it is NOT withoutGlobalScopes()
 *     on a model (which is banned in product code).
 *   - It is reachable ONLY from the platform (super-admin) panel; a merchant never
 *     calls it. There is no per-account variant here on purpose — merchant cost/margin
 *     is the merchant's own ledger, surfaced by DashboardMetrics.
 *
 * Revenue is the absolute value of the (negative) charge amounts; cost is the recorded
 * real OpenRouter spend. The markup REALIZED (revenue/cost) is computed from the
 * integer sums and compared in the UI to the configured target (CREDIT_MARKUP_DEFAULT).
 */
final class CostsMetricsBuilder
{
    // === CONSTANTS ===
    private const TABLE = 'credit_ledger';
    private const MARKUP_TARGET_CONFIG_KEY = 'trayon.pricing.markup_default';

    /**
     * Build the platform costs snapshot over $window (default: last 30 days). Counts
     * only `charge` rows — the only ledger type that carries a real OpenRouter cost.
     */
    public function build(?MetricWindow $window = null): CostsMetrics
    {
        $window ??= MetricWindow::lastDays();

        // One aggregate pass over the charge rows. Sums/counts only — no row data.
        $query = DB::table(self::TABLE)
            ->where('type', CreditLedger::TYPE_CHARGE);

        $window->constrain($query, 'created_at');

        $row = $query->selectRaw(
            'COUNT(*) as charge_count, '
            .'COALESCE(SUM(ABS(amount_micro_usd)), 0) as revenue_micro_usd, '
            .'COALESCE(SUM(actual_cost_micro_usd), 0) as actual_cost_micro_usd'
        )->first();

        $revenue = (int) ($row->revenue_micro_usd ?? 0);
        $cost = (int) ($row->actual_cost_micro_usd ?? 0);
        $chargeCount = (int) ($row->charge_count ?? 0);

        return new CostsMetrics(
            revenueMicroUsd: $revenue,
            actualCostMicroUsd: $cost,
            grossMarginMicroUsd: $revenue - $cost,
            markupRealized: $cost > 0 ? $revenue / $cost : 0.0,
            chargeCount: $chargeCount,
            markupTarget: (float) config(self::MARKUP_TARGET_CONFIG_KEY),
            window: $window,
        );
    }
}
