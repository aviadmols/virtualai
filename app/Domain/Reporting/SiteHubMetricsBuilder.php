<?php

namespace App\Domain\Reporting;

use App\Models\EndUser;
use App\Models\Generation;
use App\Models\Product;
use App\Models\Site;
use App\Support\Tenant;

/**
 * SiteHubMetricsBuilder — runs the per-shop queries that fill a SiteHubMetrics
 * snapshot for the merchant Overview hub (WS1). The aggregation lives HERE, never
 * in a Blade view.
 *
 * Tenant-safety: every query goes through a model carrying BelongsToAccount, run
 * inside Tenant::run($site->account_id) so the global scope isolates it to the
 * shop's account, and each catalog/throughput query is additionally narrowed to
 * this shop by an explicit site_id filter. There is NO withoutGlobalScopes() and
 * NO bare cross-account read; a forgotten filter fails CLOSED (returns nothing),
 * never leaks. The account is derived from the passed Site, never ambient state.
 *
 * The spendable-credit figure is account-level (the shop shares its account's
 * wallet); the low-balance WARN line reuses the same account/site threshold as
 * DashboardMetricsBuilder.
 */
final class SiteHubMetricsBuilder
{
    // === CONSTANTS ===
    // Where a site may override the low-balance WARN threshold (micro-USD).
    private const SITE_LOW_BALANCE_KEY = 'low_balance_micro_usd';
    // The account-level fallback when no site sets an override.
    private const LOW_BALANCE_CONFIG_KEY = 'trayon.credits.low_balance_micro_usd';

    /**
     * Build the shop-hub snapshot for $site over $window. Time-bounded figures
     * (try-ons in window) use $window; lifetime figures (confirmed products, leads,
     * balance) ignore it.
     */
    public function build(Site $site, ?MetricWindow $window = null): SiteHubMetrics
    {
        $window ??= MetricWindow::lastDays();
        $siteId = (int) $site->getKey();

        return Tenant::run((int) $site->account_id, function () use ($site, $siteId, $window): SiteHubMetrics {
            // Fresh account so the balance/reserved reflect the latest ledger writes
            // (an observer may have moved the column on the passed relation; TS-CREDITS-001).
            $account = ($site->account?->fresh()) ?? $site->account;

            $productsConfirmed = Product::query()
                ->where('site_id', $siteId)
                ->where('status', Product::STATUS_CONFIRMED)
                ->count();

            $generationsInWindow = $this->countGenerations($siteId, $window);
            $succeeded = $this->countGenerations($siteId, $window, Generation::STATUS_SUCCEEDED);
            $failed = $this->countGenerations($siteId, $window, Generation::STATUS_FAILED);

            $leadsTotal = EndUser::query()->where('site_id', $siteId)->count();

            $balance = (int) ($account->balance_micro_usd ?? 0);
            $reserved = (int) ($account->reserved_micro_usd ?? 0);
            $spendable = $balance - $reserved;

            return new SiteHubMetrics(
                productsConfirmed: $productsConfirmed,
                generationsInWindow: $generationsInWindow,
                generationsSucceededInWindow: $succeeded,
                generationsFailedInWindow: $failed,
                successRate: $this->successRate($succeeded, $failed),
                leadsTotal: $leadsTotal,
                spendableMicroUsd: $spendable,
                isLowBalance: $spendable <= $this->lowBalanceThreshold(),
                window: $window,
            );
        });
    }

    /** Count this shop's generations in the window, optionally filtered to one status. */
    private function countGenerations(int $siteId, MetricWindow $window, ?string $status = null): int
    {
        $query = Generation::query()->where('site_id', $siteId);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return (int) $window->constrain($query, 'created_at')->count();
    }

    /** Succeeded / (succeeded + failed) in [0,1]; 0.0 when no terminal attempts. */
    private function successRate(int $succeeded, int $failed): float
    {
        $terminal = $succeeded + $failed;

        return $terminal === 0 ? 0.0 : $succeeded / $terminal;
    }

    /**
     * The effective low-balance WARN threshold (micro-USD): the MAX per-site override
     * across the bound account's sites, else the config default. Runs inside the
     * already-bound tenant scope, so it only reads this account's sites.
     */
    private function lowBalanceThreshold(): int
    {
        $default = (int) config(self::LOW_BALANCE_CONFIG_KEY);

        $maxOverride = Site::query()
            ->get(['usage_limits'])
            ->map(fn (Site $site): ?int => $this->siteOverride($site))
            ->filter(fn (?int $value): bool => $value !== null)
            ->max();

        return $maxOverride === null ? $default : max((int) $maxOverride, $default);
    }

    /** A site's low-balance override (micro-USD) from usage_limits, or null. */
    private function siteOverride(Site $site): ?int
    {
        $limits = $site->usage_limits ?? [];

        $value = $limits[self::SITE_LOW_BALANCE_KEY] ?? null;

        return $value === null ? null : (int) $value;
    }
}
