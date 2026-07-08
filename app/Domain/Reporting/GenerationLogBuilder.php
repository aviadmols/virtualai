<?php

namespace App\Domain\Reporting;

use Illuminate\Support\Facades\DB;

/**
 * GenerationLogBuilder — a cross-account LOG of recent try-on generations for the Super-Admin: each
 * call's timestamp, account, model + provider, status, provider render time (ms) and real cost.
 *
 * Like CostsMetricsBuilder, this is the sanctioned platform cross-account read: a DB::table query
 * that returns plain rows of GENERATION METADATA only — no hydrated model, no shopper PII (no email,
 * photo, or end-user id is selected). Newest first, capped so the page stays lean.
 */
final class GenerationLogBuilder
{
    // === CONSTANTS ===
    private const TABLE = 'generations';
    private const TABLE_AI_MODELS = 'ai_models';
    private const TABLE_ACCOUNTS = 'accounts';
    private const PROVIDER_UNKNOWN = 'unknown';
    private const DEFAULT_LIMIT = 200;

    /**
     * Recent generations over $window (newest first, capped at $limit).
     *
     * @return array<int,array{id:int,createdAt:?string,accountName:string,modelUsed:?string,provider:string,status:string,durationMs:?int,costMicroUsd:?int}>
     */
    public function recent(?MetricWindow $window = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $window ??= MetricWindow::lastDays();

        // One provider per model_id (a model catalogued under two ops must not fan the row out).
        $providerMap = DB::table(self::TABLE_AI_MODELS)
            ->select('model_id')
            ->selectRaw('MIN(provider) as provider')
            ->groupBy('model_id');

        $query = DB::table(self::TABLE.' as g')
            ->join(self::TABLE_ACCOUNTS.' as a', 'a.id', '=', 'g.account_id')
            ->leftJoinSub($providerMap, 'm', 'm.model_id', '=', 'g.model_used');

        $window->constrain($query, 'g.created_at');

        return $query->selectRaw(
            'g.id as id, g.created_at as created_at, a.name as account_name, g.model_used as model_used, '
            ."COALESCE(m.provider, '".self::PROVIDER_UNKNOWN."') as provider, g.status as status, "
            .'g.duration_ms as duration_ms, g.actual_cost_micro_usd as cost'
        )
            ->orderByDesc('g.created_at')
            ->orderByDesc('g.id')
            ->limit($limit)
            ->get()
            ->map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'createdAt' => $r->created_at !== null ? (string) $r->created_at : null,
                'accountName' => (string) $r->account_name,
                'modelUsed' => $r->model_used !== null ? (string) $r->model_used : null,
                'provider' => (string) $r->provider,
                'status' => (string) $r->status,
                'durationMs' => $r->duration_ms !== null ? (int) $r->duration_ms : null,
                'costMicroUsd' => $r->cost !== null ? (int) $r->cost : null,
            ])
            ->all();
    }
}
