<?php

namespace App\Domain\Reporting;

use App\Domain\Ai\Contracts\ImageGenerationProvider;
use App\Models\CreditLedger;
use App\Models\Generation;
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

    // Operation tables (each carries model_used + actual_cost_micro_usd together) + the model
    // catalog that maps model_used -> provider, and the tenant-root accounts table (global).
    private const TABLE_GENERATIONS = 'generations';
    private const TABLE_BANNER_ASSETS = 'banner_assets';
    private const TABLE_AI_MODELS = 'ai_models';
    private const TABLE_ACCOUNTS = 'accounts';

    // A used model absent from the catalog buckets here (retired/legacy id).
    private const PROVIDER_UNKNOWN = 'unknown';

    // Default cap on the per-account table (top spenders by revenue).
    private const ACCOUNT_LIMIT = 25;

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

    /**
     * Provider spend over $window — the real cost we paid EACH AI provider (openrouter / byteplus).
     * Read from the SUCCEEDED operation rows (generations + banner_assets), which carry model_used
     * and actual_cost_micro_usd together; the provider is derived model_used -> ai_models.provider,
     * deduped to one provider per model_id so a model catalogued under two operations is never
     * double-counted. Known providers are always present (0 when idle) so the comparison is complete.
     *
     * @return array<int,array{provider:string,costMicroUsd:int,count:int}>
     */
    public function byProvider(?MetricWindow $window = null): array
    {
        $window ??= MetricWindow::lastDays();

        $totals = array_fill_keys(ImageGenerationProvider::PROVIDERS, ['cost' => 0, 'count' => 0]);

        foreach ([self::TABLE_GENERATIONS, self::TABLE_BANNER_ASSETS] as $table) {
            foreach ($this->providerSpend($table, $window) as $row) {
                $provider = (string) $row->provider;
                $totals[$provider] ??= ['cost' => 0, 'count' => 0];
                $totals[$provider]['cost'] += (int) $row->cost;
                $totals[$provider]['count'] += (int) $row->n;
            }
        }

        $out = [];
        foreach ($totals as $provider => $t) {
            $out[] = ['provider' => $provider, 'costMicroUsd' => $t['cost'], 'count' => $t['count']];
        }

        usort($out, static fn (array $a, array $b): int => $b['costMicroUsd'] <=> $a['costMicroUsd']);

        return $out;
    }

    /**
     * Per-account (merchant) cost vs revenue over $window: for each account with charges, the real
     * cost we paid vs the selling value it was billed, plus margin, realized markup + charge count.
     * From the charge ledger rows (the money source of truth), joined to accounts for the name.
     * Ordered by revenue, capped at $limit. Sums-only DB::table aggregate (never a hydrated model).
     *
     * @return array<int,array{accountId:int,accountName:string,revenueMicroUsd:int,costMicroUsd:int,marginMicroUsd:int,markupRealized:float,charges:int}>
     */
    public function byAccount(?MetricWindow $window = null, int $limit = self::ACCOUNT_LIMIT): array
    {
        $window ??= MetricWindow::lastDays();

        $query = DB::table(self::TABLE.' as c')
            ->join(self::TABLE_ACCOUNTS.' as a', 'a.id', '=', 'c.account_id')
            ->where('c.type', CreditLedger::TYPE_CHARGE);

        $window->constrain($query, 'c.created_at');

        return $query->selectRaw(
            'c.account_id as account_id, a.name as account_name, '
            .'SUM(ABS(c.amount_micro_usd)) as revenue, '
            .'COALESCE(SUM(c.actual_cost_micro_usd), 0) as cost, '
            .'COUNT(*) as charges'
        )
            ->groupBy('c.account_id', 'a.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(static function (object $row): array {
                $revenue = (int) $row->revenue;
                $cost = (int) $row->cost;

                return [
                    'accountId' => (int) $row->account_id,
                    'accountName' => (string) $row->account_name,
                    'revenueMicroUsd' => $revenue,
                    'costMicroUsd' => $cost,
                    'marginMicroUsd' => $revenue - $cost,
                    'markupRealized' => $cost > 0 ? $revenue / $cost : 0.0,
                    'charges' => (int) $row->charges,
                ];
            })
            ->all();
    }

    /** Per-provider cost + count from one operation table's SUCCEEDED rows over the window. */
    private function providerSpend(string $table, MetricWindow $window): \Illuminate\Support\Collection
    {
        // One provider per model_id, so a model catalogued under both operations isn't matched twice.
        $providerMap = DB::table(self::TABLE_AI_MODELS)
            ->select('model_id')
            ->selectRaw('MIN(provider) as provider')
            ->groupBy('model_id');

        $query = DB::table($table.' as o')
            ->leftJoinSub($providerMap, 'm', 'm.model_id', '=', 'o.model_used')
            ->where('o.status', Generation::STATUS_SUCCEEDED)
            ->whereNotNull('o.actual_cost_micro_usd');

        $window->constrain($query, 'o.created_at');

        return $query
            ->selectRaw("COALESCE(m.provider, '".self::PROVIDER_UNKNOWN."') as provider, SUM(o.actual_cost_micro_usd) as cost, COUNT(*) as n")
            ->groupBy('provider')
            ->get();
    }
}
